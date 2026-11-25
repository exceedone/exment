<?php

namespace Exceedone\Exment\Services\LogManagement;

use Exceedone\Exment\Model\PermissionChangeLog;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;

/**
 * The history of the changes of the role groups (ISS02840). A change is found by the state of a
 * group before and after it (its permissions and its members), as the pages, the API and the
 * other places write them in different ways (models, bulk queries). Ids only, besides the name
 * of the group. A record that cannot be written never stops the change.
 *
 *     PermissionChanges::around($roleGroupId, function () { ... }, PermissionChanges::SOURCE_PAGE);
 */
class PermissionChanges
{
    public const SOURCE_PAGE = 'page';
    public const SOURCE_API = 'api';
    public const SOURCE_DELETED = 'deleted';

    /**
     * The state of a group: its name and its items, each "permission|type|target|permission" or
     * "member|type|id" (null: no such group).
     *
     * @param mixed $roleGroupId
     * @return array{name: string, items: array<string, true>}|null
     */
    public static function snapshot($roleGroupId): ?array
    {
        if (is_nullorempty($roleGroupId) || !PermissionChangeLog::tableExists()) {
            return null;
        }
        try {
            /** @var RoleGroup|null $group */
            $group = RoleGroup::query()->find($roleGroupId);
            if (!isset($group)) {
                return null;
            }
            $items = [];
            foreach (RoleGroupPermission::query()->where('role_group_id', $roleGroupId)->get() as $permission) {
                foreach (array_filter((array)($permission->permissions ?? [])) as $key) {
                    $items[implode('|', ['permission', strval($permission->role_group_permission_type), strval($permission->role_group_target_id), strval($key)])] = true;
                }
            }
            foreach (RoleGroupUserOrganization::query()->where('role_group_id', $roleGroupId)->get() as $member) {
                $items[implode('|', ['member', strval($member->role_group_user_org_type), strval($member->role_group_target_id)])] = true;
            }
            return ['name' => strval($group->role_group_view_name ?? $group->role_group_name), 'items' => $items];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Run a change of groups and record what it changed.
     *
     * @param mixed $roleGroupIds one id or a list
     * @param \Closure $change
     * @return mixed what $change returns
     */
    public static function around($roleGroupIds, \Closure $change, string $source = self::SOURCE_PAGE)
    {
        $before = [];
        foreach (array_unique(array_filter((array)$roleGroupIds)) as $id) {
            $before[$id] = static::snapshot($id);
        }
        $result = $change();
        foreach ($before as $id => $snapshot) {
            static::record($id, $snapshot, static::snapshot($id), $source);
        }
        return $result;
    }

    /**
     * Record the difference of two states of a group (null before: made; null after: deleted).
     *
     * @param mixed $roleGroupId
     * @param array{name: string, items: array<string, true>}|null $before
     * @param array{name: string, items: array<string, true>}|null $after
     * @return int the records written
     */
    public static function record($roleGroupId, ?array $before, ?array $after, string $source = self::SOURCE_PAGE): int
    {
        if (!PermissionChangeLog::tableExists() || (is_null($before) && is_null($after))) {
            return 0;
        }
        $name = strval(($after ?? $before)['name']);
        $rows = [];
        if (is_null($before)) {
            $rows[] = static::row(PermissionChangeLog::CHANGE_ADDED, PermissionChangeLog::KIND_GROUP);
        }
        $old = $before['items'] ?? [];
        $new = $after['items'] ?? [];
        foreach (array_keys(array_diff_key($new, $old)) as $item) {
            $rows[] = static::itemRow(PermissionChangeLog::CHANGE_ADDED, $item);
        }
        foreach (array_keys(array_diff_key($old, $new)) as $item) {
            $rows[] = static::itemRow(PermissionChangeLog::CHANGE_REMOVED, $item);
        }
        if (is_null($after)) {
            $rows[] = static::row(PermissionChangeLog::CHANGE_REMOVED, PermissionChangeLog::KIND_GROUP);
        }
        if (count($rows) === 0) {
            return 0;
        }

        $userId = null;
        try {
            $userId = \Exment::getUserId();
        } catch (\Throwable $e) {
            // a job, the console
        }
        $now = now();
        foreach ($rows as &$row) {
            $row['role_group_id'] = intval($roleGroupId);
            $row['role_group_name'] = mb_substr($name, 0, 256);
            $row['changed_user_id'] = is_nullorempty($userId) ? null : intval($userId);
            $row['source'] = $source;
            $row['created_at'] = $now;
        }
        unset($row);
        try {
            PermissionChangeLog::query()->insert($rows);
        } catch (\Throwable $e) {
            \Log::warning('The change of a role group could not be recorded: ' . $e->getMessage());
            return 0;
        }
        return count($rows);
    }

    /**
     * The groups a user or an organization is a member of (before it is deleted).
     *
     * @param mixed $targetId
     * @return array<int, int>
     */
    public static function groupsOfMember(string $targetType, $targetId): array
    {
        if (!PermissionChangeLog::tableExists()) {
            return [];
        }
        return RoleGroupUserOrganization::query()
            ->where('role_group_user_org_type', $targetType)
            ->where('role_group_target_id', $targetId)
            ->distinct()
            ->pluck('role_group_id')
            ->map(function ($id) {
                return intval($id);
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected static function itemRow(string $change, string $item): array
    {
        $parts = explode('|', $item);
        if ($parts[0] === 'permission') {
            return static::row($change, PermissionChangeLog::KIND_PERMISSION, [
                'role_type' => $parts[1] ?? null,
                'target_id' => is_numeric($parts[2] ?? null) ? intval($parts[2]) : null,
                'permission' => mb_substr(strval($parts[3] ?? ''), 0, 64),
            ]);
        }
        return static::row($change, PermissionChangeLog::KIND_MEMBER, [
            'target_type' => $parts[1] ?? null,
            'target_id' => is_numeric($parts[2] ?? null) ? intval($parts[2]) : null,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function row(string $change, string $kind, array $values = []): array
    {
        return array_merge([
            'change' => $change,
            'kind' => $kind,
            'role_type' => null,
            'target_type' => null,
            'target_id' => null,
            'permission' => null,
        ], $values);
    }
}

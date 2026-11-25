<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Reading the members (ISS00335): the users of an organization, the organizations of a user,
 * the role groups with their users and organizations. The API (Controllers\ApiMemberController)
 * and the MCP server (Mcp\McpTools) both answer from here: the same rule, the same items.
 *
 * Who may read them: the feature api_members is used, and the user is a system administrator
 * or has the permission "role group: all" or "role group: users and organizations".
 */
class MemberDirectory
{
    public const FEATURE = 'api_members';

    /**
     * Whether the user may read (and, by the API, change) the members.
     *
     * @param mixed $user a login user (null: nobody)
     */
    public static function allowed($user): bool
    {
        if (!Features::enabled(static::FEATURE)) {
            return false;
        }
        return isset($user) && $user->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_USER_ORGANIZATION]);
    }

    /**
     * The organization and its users; null when there is no such organization.
     *
     * @return array{organization: array<string, mixed>, users: array<int, array<string, mixed>>}|null
     */
    public static function organizationUsers(mixed $id): ?array
    {
        $organization = static::findValue(SystemTableName::ORGANIZATION, $id);
        if (!isset($organization)) {
            return null;
        }
        return [
            'organization' => static::valueItem($organization),
            'users' => static::items(SystemTableName::USER, static::userIdsOf(intval($organization->id))->all()),
        ];
    }

    /**
     * The user and the organizations of the user; null when there is no such user.
     *
     * @return array{user: array<string, mixed>, organizations: array<int, array<string, mixed>>}|null
     */
    public static function userOrganizations(mixed $id): ?array
    {
        $user = static::findValue(SystemTableName::USER, $id);
        if (!isset($user)) {
            return null;
        }
        $orgIds = DB::table(static::pivotTable())->where('child_id', $user->id)->pluck('parent_id')->all();
        return [
            'user' => static::valueItem($user),
            'organizations' => static::items(SystemTableName::ORGANIZATION, $orgIds),
        ];
    }

    /**
     * The role groups (without their members).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function roleGroups(): array
    {
        return RoleGroup::query()->orderBy('role_group_order')->orderBy('id')->get()->map(function (RoleGroup $roleGroup) {
            return static::roleGroupItem($roleGroup, false);
        })->values()->all();
    }

    public static function findRoleGroup(mixed $id): ?RoleGroup
    {
        return is_numeric($id) ? RoleGroup::query()->whereKey(intval($id))->first() : null;
    }

    /**
     * One role group with its permissions, users and organizations; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function roleGroup(mixed $id): ?array
    {
        $roleGroup = static::findRoleGroup($id);
        return isset($roleGroup) ? static::roleGroupItem($roleGroup, true) : null;
    }

    /**
     * Whether the role group gives the system permission.
     */
    public static function givesSystem(RoleGroup $roleGroup): bool
    {
        foreach ($roleGroup->role_group_permissions as $permission) {
            if (strval($permission->role_group_permission_type) !== strval(RoleType::SYSTEM)) {
                continue;
            }
            $details = (array)$permission->permissions;
            if (array_key_exists(Permission::SYSTEM, $details) || in_array(Permission::SYSTEM, $details, true)) {
                return true;
            }
        }
        return false;
    }

    public static function pivotTable(): string
    {
        return CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function userIdsOf(int $orgId): \Illuminate\Support\Collection
    {
        return DB::table(static::pivotTable())->where('parent_id', $orgId)->pluck('child_id')->map(fn ($id) => intval($id));
    }

    public static function findValue(string $tableName, mixed $id): ?CustomValue
    {
        if (!is_numeric($id)) {
            return null;
        }
        $value = getModelName($tableName)::query()->find(intval($id));
        return $value instanceof CustomValue ? $value : null;
    }

    /**
     * The id, the label and the stored code, name and email (no more than that).
     *
     * @return array<string, mixed>
     */
    public static function valueItem(CustomValue $value): array
    {
        $item = [
            'id' => intval($value->id),
            'label' => strval($value->getLabel()),
        ];
        foreach (['user_code', 'user_name', 'email', 'organization_code', 'organization_name'] as $column) {
            $v = $value->getValue($column);
            if (isset($v)) {
                $item[$column] = $v;
            }
        }
        return $item;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array<string, mixed>>
     */
    protected static function items(string $tableName, array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $value = static::findValue($tableName, $id);
            if (isset($value)) {
                $items[] = static::valueItem($value);
            }
        }
        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function roleGroupItem(RoleGroup $roleGroup, bool $members): array
    {
        $targets = RoleGroupUserOrganization::query()->where('role_group_id', $roleGroup->id)->get();
        $ids = function (string $type) use ($targets) {
            return $targets->where('role_group_user_org_type', $type)->pluck('role_group_target_id')->map(fn ($id) => intval($id))->values();
        };
        $item = [
            'id' => intval($roleGroup->id),
            'role_group_name' => $roleGroup->role_group_name,
            'role_group_view_name' => $roleGroup->role_group_view_name,
            'description' => $roleGroup->description,
            'system' => static::givesSystem($roleGroup),
            'user_count' => $ids(SystemTableName::USER)->count(),
            'organization_count' => $ids(SystemTableName::ORGANIZATION)->count(),
        ];
        if (!$members) {
            return $item;
        }
        $item['permissions'] = $roleGroup->role_group_permissions->map(function ($permission) {
            return [
                'role_group_permission_type' => intval($permission->role_group_permission_type),
                'role_group_target_id' => $permission->role_group_target_id,
                'permissions' => $permission->permissions,
            ];
        })->values()->all();
        foreach ([SystemTableName::USER, SystemTableName::ORGANIZATION] as $type) {
            $item[$type] = static::items($type, $ids($type)->all());
        }
        return $item;
    }
}

<?php

namespace Exceedone\Exment\Services\AuditReport;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;

/**
 * Who may read and change the data of which table (ISS02911), for all the users at once: the
 * members of the role groups, the users of the organizations and the tree of the organizations, and
 * the permissions of the groups, each read with one query and put together here, not a query a
 * user. The same rule as the permissions of a request (CustomTable::hasPermission and
 * AuthUserOrgHelper::getOrganizationIdsForQuery): a permission of the system "system" or
 * "custom_table" gives every table, "custom_value_edit_all" the data of every table.
 */
class PermissionMatrix
{
    /** The table permissions, the strongest first. */
    public const LEVELS = [
        Permission::CUSTOM_TABLE,
        Permission::CUSTOM_VALUE_EDIT_ALL,
        Permission::CUSTOM_VALUE_VIEW_ALL,
        Permission::CUSTOM_VALUE_ACCESS_ALL,
        Permission::CUSTOM_VALUE_EDIT,
        Permission::CUSTOM_VALUE_VIEW,
    ];

    /** The id of "every table". */
    public const ALL_TABLES = 0;

    /** @var array<int, array<int, array{level: string, sources: array<int, string>}>>|null base user id => table id (0: all) => the strongest permission and where it comes from */
    protected $matrix = null;

    /** @var array<int, string> the names of the role groups */
    protected $groupNames = [];

    /**
     * @param array<int, int> $userIds the base users
     * @param array<int, string> $admins the system administrators (base user ids)
     */
    public function __construct(protected array $userIds, protected array $admins)
    {
    }

    public static function canEdit(string $level): bool
    {
        return in_array($level, Permission::AVAILABLE_EDIT_CUSTOM_VALUE, true);
    }

    public static function canView(string $level): bool
    {
        return in_array($level, Permission::AVAILABLE_VIEW_CUSTOM_VALUE, true);
    }

    /**
     * @return array<int, array<int, array{level: string, sources: array<int, string>}>>
     */
    public function matrix(): array
    {
        if (isset($this->matrix)) {
            return $this->matrix;
        }
        $matrix = [];
        $add = function (int $userId, int $tableId, string $level, string $source) use (&$matrix) {
            $current = $matrix[$userId][$tableId] ?? null;
            if (is_null($current)) {
                $matrix[$userId][$tableId] = ['level' => $level, 'sources' => [$source]];
                return;
            }
            if (array_search($level, static::LEVELS, true) < array_search($current['level'], static::LEVELS, true)) {
                $matrix[$userId][$tableId]['level'] = $level;
            }
            if (!in_array($source, $current['sources'], true)) {
                $matrix[$userId][$tableId]['sources'][] = $source;
            }
        };
        $systemText = strval(exmtrans('audit_report.permission_list.system_admin'));
        foreach ($this->userIds as $userId) {
            if (in_array(strval($userId), $this->admins, true)) {
                $add($userId, static::ALL_TABLES, Permission::CUSTOM_TABLE, $systemText);
            }
        }
        if (!boolval(System::permission_available())) {
            // without the permissions, every user may do everything
            foreach ($this->userIds as $userId) {
                $add($userId, static::ALL_TABLES, Permission::CUSTOM_TABLE, strval(exmtrans('audit_report.permission_list.no_permission_setting')));
            }
            return $this->matrix = $matrix;
        }

        // a table that every user may change, read or refer (its settings), as a permission of each
        foreach (CustomTable::allRecords() as $table) {
            foreach (['all_user_editable_flg' => Permission::CUSTOM_VALUE_EDIT_ALL, 'all_user_viewable_flg' => Permission::CUSTOM_VALUE_VIEW_ALL, 'all_user_accessable_flg' => Permission::CUSTOM_VALUE_ACCESS_ALL] as $flag => $level) {
                if (!boolval($table->getOption($flag))) {
                    continue;
                }
                $source = strval(exmtrans('audit_report.permission_list.' . $flag));
                foreach ($this->userIds as $userId) {
                    $add($userId, intval($table->id), $level, $source);
                }
            }
        }

        // what each group gives: table id (0: all) => the strongest permission
        $gives = [];
        foreach (RoleGroupPermission::query()->get() as $permission) {
            $keys = array_values(array_filter((array)($permission->permissions ?? [])));
            $type = strval($permission->role_group_permission_type);
            $group = intval($permission->role_group_id);
            if ($type === strval(RoleType::SYSTEM)) {
                if (in_array(Permission::SYSTEM, $keys, true) || in_array(Permission::CUSTOM_TABLE, $keys, true)) {
                    $gives[$group][static::ALL_TABLES][] = Permission::CUSTOM_TABLE;
                } elseif (in_array(Permission::CUSTOM_VALUE_EDIT_ALL, $keys, true)) {
                    $gives[$group][static::ALL_TABLES][] = Permission::CUSTOM_VALUE_EDIT_ALL;
                }
            } elseif ($type === strval(RoleType::TABLE)) {
                foreach (static::LEVELS as $level) {
                    if (in_array($level, $keys, true)) {
                        $gives[$group][intval($permission->role_group_target_id)][] = $level;
                        break;
                    }
                }
            }
        }
        if (count($gives) === 0) {
            return $this->matrix = $matrix;
        }
        foreach (RoleGroup::query()->whereIn('id', array_keys($gives))->get(['id', 'role_group_view_name']) as $group) {
            $this->groupNames[intval($group->id)] = strval($group->role_group_view_name);
        }

        // the groups of each user, with where they come from
        $userGroups = [];
        $orgGroups = [];
        foreach (RoleGroupUserOrganization::query()->whereIn('role_group_id', array_keys($gives))->get() as $member) {
            $group = intval($member->role_group_id);
            if ($member->role_group_user_org_type === SystemTableName::USER) {
                $userGroups[intval($member->role_group_target_id)][$group] = $this->groupNames[$group] ?? '#' . $group;
            } elseif ($member->role_group_user_org_type === SystemTableName::ORGANIZATION) {
                $orgGroups[intval($member->role_group_target_id)][] = $group;
            }
        }
        if (count($orgGroups) > 0 && boolval(System::organization_available())) {
            foreach ($this->organizationGroups($orgGroups) as $userId => $groups) {
                foreach ($groups as $group => $source) {
                    $userGroups[$userId][$group] ??= $source;
                }
            }
        }

        $wanted = array_flip($this->userIds);
        foreach ($userGroups as $userId => $groups) {
            if (!isset($wanted[$userId])) {
                continue;
            }
            foreach ($groups as $group => $source) {
                foreach ($gives[$group] ?? [] as $tableId => $levels) {
                    foreach ($levels as $level) {
                        $add($userId, intval($tableId), $level, $source);
                    }
                }
            }
        }
        return $this->matrix = $matrix;
    }

    /**
     * The groups the users get through their organizations: the organizations of a user, and the
     * ones above or below them as the system setting says (as the permissions of a request).
     *
     * @param array<int, array<int, int>> $orgGroups organization id => groups
     * @return array<int, array<int, string>> base user id => group id => "organization → group"
     */
    protected function organizationGroups(array $orgGroups): array
    {
        $modelname = getModelName(SystemTableName::ORGANIZATION);
        $indexName = $modelname::getParentOrgIndexName();
        $parents = [];
        $children = [];
        $names = [];
        foreach ($modelname::withoutGlobalScopes([CustomValueModelScope::class])->get() as $org) {
            $id = intval($org->id);
            $parent = $org->{$indexName} ?? null;
            if (is_null($parent)) {
                $value = $org->getValue('parent_organization');
                $parent = is_object($value) ? ($value->id ?? null) : $value;
            }
            $parents[$id] = is_numeric($parent) ? intval($parent) : null;
            if (is_numeric($parent)) {
                $children[intval($parent)][] = $id;
            }
            $names[$id] = strval($org->getValue('organization_name'));
        }
        $filter = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
        $downer = JoinedOrgFilterType::isGetDowner($filter);
        $upper = JoinedOrgFilterType::isGetUpper($filter);

        // the organizations whose groups reach a user of an organization
        $reach = function (int $org) use ($parents, $children, $downer, $upper): array {
            $ids = [$org];
            if ($downer) {
                $seen = [$org => true];
                $at = $parents[$org] ?? null;
                while (isset($at) && !isset($seen[$at])) {
                    $seen[$at] = true;
                    $ids[] = $at;
                    $at = $parents[$at] ?? null;
                }
            }
            if ($upper) {
                $stack = $children[$org] ?? [];
                $seen = [$org => true];
                while (count($stack) > 0) {
                    $child = array_pop($stack);
                    if (isset($seen[$child])) {
                        continue;
                    }
                    $seen[$child] = true;
                    $ids[] = $child;
                    foreach ($children[$child] ?? [] as $next) {
                        $stack[] = $next;
                    }
                }
            }
            return $ids;
        };

        $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        $result = [];
        $cache = [];
        foreach (\DB::table($pivot)->get(['parent_id', 'child_id']) as $row) {
            $joined = intval($row->parent_id);
            $userId = intval($row->child_id);
            $cache[$joined] ??= $reach($joined);
            foreach ($cache[$joined] as $org) {
                foreach ($orgGroups[$org] ?? [] as $group) {
                    $result[$userId][$group] ??= ($names[$org] ?? '#' . $org) . ' → ' . ($this->groupNames[$group] ?? '#' . $group);
                }
            }
        }
        return $result;
    }
}

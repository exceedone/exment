<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API of the members (ISS00335): the users of an organization, the organizations of a
 * user, and the role groups with their users and organizations. Before it they could be
 * changed only on the pages.
 *
 * - Only the users who may change the members of the role groups on the pages: the system
 *   administrators, or the permission "role group: all" or "role group: users and
 *   organizations". A role group that gives the system permission: the system
 *   administrators only (else anyone with the permission could make themselves one).
 * - Its own scopes (ApiScope::USER_ORG_*, ROLE_GROUP_*), and the feature api_members (off
 *   until it is turned on).
 * - The requests are in the operation log (the "adminapi" middleware); the caches of the
 *   permissions are cleared as on the pages.
 */
class ApiMemberController extends AdminControllerBase
{
    use ApiTrait;

    public const FEATURE = 'api_members';

    /** The most ids in one request. */
    public const MAX_IDS = 1000;

    // the users of an organization ----------------------------------------------------

    /**
     * GET organization/{id}/users
     *
     * @return Response|array<string, mixed>
     */
    public function organizationUsers(Request $request, string|int $id)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        $organization = static::findValue(SystemTableName::ORGANIZATION, $id);
        if (!isset($organization)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        return [
            'organization' => static::valueItem($organization),
            'users' => static::userIdsOf(intval($organization->id))->map(function ($userId) {
                return static::findValue(SystemTableName::USER, $userId);
            })->filter()->map(function ($user) {
                return static::valueItem($user);
            })->values()->all(),
        ];
    }

    /**
     * POST organization/{id}/users {"users": [1, 2]}
     *
     * @return Response|array<string, mixed>
     */
    public function addOrganizationUsers(Request $request, string|int $id)
    {
        return $this->changeOrganizationUsers($request, $id, true);
    }

    /**
     * DELETE organization/{id}/users {"users": [1, 2]}
     *
     * @return Response|array<string, mixed>
     */
    public function removeOrganizationUsers(Request $request, string|int $id)
    {
        return $this->changeOrganizationUsers($request, $id, false);
    }

    /**
     * GET user/{id}/organizations
     *
     * @return Response|array<string, mixed>
     */
    public function userOrganizations(Request $request, string|int $id)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        $user = static::findValue(SystemTableName::USER, $id);
        if (!isset($user)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        $orgIds = DB::table(static::pivotTable())->where('child_id', $user->id)->pluck('parent_id');
        return [
            'user' => static::valueItem($user),
            'organizations' => collect($orgIds)->map(function ($orgId) {
                return static::findValue(SystemTableName::ORGANIZATION, $orgId);
            })->filter()->map(function ($org) {
                return static::valueItem($org);
            })->values()->all(),
        ];
    }

    /**
     * @return Response|array<string, mixed>
     */
    protected function changeOrganizationUsers(Request $request, mixed $id, bool $add)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        $organization = static::findValue(SystemTableName::ORGANIZATION, $id);
        if (!isset($organization)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        [$userIds, $error] = static::ids($request->input('users'), SystemTableName::USER, 'users');
        if (isset($error)) {
            return $error;
        }

        $orgId = intval($organization->id);
        $pivot = static::pivotTable();
        DB::transaction(function () use ($pivot, $orgId, $userIds, $add) {
            $now = static::userIdsOf($orgId)->all();
            if ($add) {
                foreach (array_diff($userIds, $now) as $userId) {
                    DB::table($pivot)->insert(['parent_id' => $orgId, 'child_id' => $userId]);
                }
            } else {
                DB::table($pivot)->where('parent_id', $orgId)->whereIn('child_id', $userIds)->delete();
            }
        });
        // the shares and the permissions by organization (as on the pages)
        System::clearCache();

        return $this->organizationUsers($request, $orgId);
    }

    // the role groups ------------------------------------------------------------------

    /**
     * GET role_group
     *
     * @return Response|array<int, array<string, mixed>>
     */
    public function roleGroups(Request $request)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        return RoleGroup::query()->orderBy('role_group_order')->orderBy('id')->get()->map(function (RoleGroup $roleGroup) {
            return static::roleGroupItem($roleGroup, false);
        })->values()->all();
    }

    /**
     * GET role_group/{id}
     *
     * @return Response|array<string, mixed>
     */
    public function roleGroup(Request $request, string|int $id)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        $roleGroup = RoleGroup::find($id);
        if (!isset($roleGroup)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        return static::roleGroupItem($roleGroup, true);
    }

    /**
     * POST role_group/{id}/members {"user": [1], "organization": [2]}
     *
     * @return Response|array<string, mixed>
     */
    public function addRoleGroupMembers(Request $request, string|int $id)
    {
        return $this->changeRoleGroupMembers($request, $id, true);
    }

    /**
     * DELETE role_group/{id}/members {"user": [1], "organization": [2]}
     *
     * @return Response|array<string, mixed>
     */
    public function removeRoleGroupMembers(Request $request, string|int $id)
    {
        return $this->changeRoleGroupMembers($request, $id, false);
    }

    /**
     * @return Response|array<string, mixed>
     */
    protected function changeRoleGroupMembers(Request $request, mixed $id, bool $add)
    {
        if (($denied = static::denied()) !== null) {
            return $denied;
        }
        $roleGroup = RoleGroup::find($id);
        if (!isset($roleGroup)) {
            return abortJson(400, ErrorCode::DATA_NOT_FOUND());
        }
        // a group of the system administrators: only they change it
        if (static::givesSystem($roleGroup) && !\Exment::user()->isAdministrator()) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }

        $targets = [];
        foreach ([SystemTableName::USER, SystemTableName::ORGANIZATION] as $type) {
            [$ids, $error] = static::ids($request->input($type), $type, $type, true);
            if (isset($error)) {
                return $error;
            }
            $targets[$type] = $ids;
        }
        if (count($targets[SystemTableName::USER]) + count($targets[SystemTableName::ORGANIZATION]) === 0) {
            return abortJson(400, exmtrans('api.errors.members_empty'), ErrorCode::VALIDATION_ERROR());
        }

        $roleGroupId = intval($roleGroup->id);
        DB::transaction(function () use ($roleGroupId, $targets, $add) {
            foreach ($targets as $type => $ids) {
                if (count($ids) === 0) {
                    continue;
                }
                $query = RoleGroupUserOrganization::query()->where('role_group_id', $roleGroupId)->where('role_group_user_org_type', $type);
                if ($add) {
                    $now = (clone $query)->pluck('role_group_target_id')->map('intval')->all();
                    foreach (array_diff($ids, $now) as $targetId) {
                        RoleGroupUserOrganization::query()->insert([
                            'role_group_id' => $roleGroupId,
                            'role_group_user_org_type' => $type,
                            'role_group_target_id' => $targetId,
                        ]);
                    }
                } else {
                    $query->whereIn('role_group_target_id', $ids)->delete();
                }
            }
        });
        // the permissions (as on the page of the role group)
        System::clearCache();

        return static::roleGroupItem(RoleGroup::findOrFail($roleGroupId), true);
    }

    // ----------------------------------------------------------------------------------

    /**
     * Not allowed: the feature, then the permission (the scope is the route's).
     */
    protected static function denied(): ?Response
    {
        if (!Features::enabled(static::FEATURE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        $user = \Exment::user();
        if (!isset($user) || !$user->hasPermission([Permission::ROLE_GROUP_ALL, Permission::ROLE_GROUP_USER_ORGANIZATION])) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        return null;
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

    /**
     * The ids of a request: numbers of rows that are there.
     *
     * @return array{0: array<int, int>, 1: Response|null}
     */
    protected static function ids(mixed $value, string $tableName, string $name, bool $optional = false): array
    {
        if (is_null($value) && $optional) {
            return [[], null];
        }
        if (is_scalar($value)) {
            $value = explode(',', strval($value));
        }
        if (!is_array($value) || (count($value) === 0 && !$optional)) {
            return [[], abortJson(400, exmtrans('api.errors.members_ids', ['name' => $name]), ErrorCode::VALIDATION_ERROR())];
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_numeric($id) || intval($id) != $id || intval($id) <= 0) {
                return [[], abortJson(400, exmtrans('api.errors.members_ids', ['name' => $name]), ErrorCode::VALIDATION_ERROR())];
            }
            $ids[] = intval($id);
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > static::MAX_IDS) {
            return [[], abortJson(400, exmtrans('api.errors.over_maxcount', static::MAX_IDS), ErrorCode::INVALID_PARAMS())];
        }
        $found = getModelName($tableName)::query()->whereIn('id', $ids)->pluck('id')->map('intval')->all();
        $missing = array_values(array_diff($ids, $found));
        if (count($missing) > 0) {
            return [[], abortJson(400, exmtrans('api.errors.members_not_found', ['name' => $name, 'ids' => implode(',', $missing)]), ErrorCode::DATA_NOT_FOUND())];
        }
        return [$ids, null];
    }

    protected static function pivotTable(): string
    {
        return CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected static function userIdsOf(int $orgId): \Illuminate\Support\Collection
    {
        return DB::table(static::pivotTable())->where('parent_id', $orgId)->pluck('child_id')->map('intval');
    }

    protected static function findValue(string $tableName, mixed $id): ?CustomValue
    {
        if (!is_numeric($id)) {
            return null;
        }
        $value = getModelName($tableName)::query()->find(intval($id));
        return $value instanceof CustomValue ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function valueItem(CustomValue $value): array
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
     * @return array<string, mixed>
     */
    protected static function roleGroupItem(RoleGroup $roleGroup, bool $members): array
    {
        $targets = RoleGroupUserOrganization::query()->where('role_group_id', $roleGroup->id)->get();
        $ids = function (string $type) use ($targets) {
            return $targets->where('role_group_user_org_type', $type)->pluck('role_group_target_id')->map('intval')->values();
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
            $item[$type] = $ids($type)->map(function ($id) use ($type) {
                return static::findValue($type, $id);
            })->filter()->map(function ($value) {
                return static::valueItem($value);
            })->values()->all();
        }
        return $item;
    }
}

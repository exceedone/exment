<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\MemberDirectory;
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

    public const FEATURE = MemberDirectory::FEATURE;

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
        return MemberDirectory::organizationUsers($id) ?? abortJson(400, ErrorCode::DATA_NOT_FOUND());
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
        return MemberDirectory::userOrganizations($id) ?? abortJson(400, ErrorCode::DATA_NOT_FOUND());
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
        // an organization that gives a group of the system administrators: only they change it
        if (!\Exment::user()->isAdministrator() && static::organizationGivesSystem($organization)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
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
        return MemberDirectory::roleGroups();
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
        return MemberDirectory::roleGroup($id) ?? abortJson(400, ErrorCode::DATA_NOT_FOUND());
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
        $roleGroup = MemberDirectory::findRoleGroup($id);
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
                    $now = (clone $query)->pluck('role_group_target_id')->map(fn ($id) => intval($id))->all();
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
        if (!MemberDirectory::allowed(\Exment::user())) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        return null;
    }

    /**
     * Whether a user of the organization is in a role group that gives the system permission:
     * a group of the organization, or of its parents or children as the setting of the role
     * groups joins them (the permissions of a user, HasPermissions).
     */
    public static function organizationGivesSystem(CustomValue $organization): bool
    {
        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
        $ids = [intval($organization->id)];
        // @phpstan-ignore-next-line the organization model
        foreach ((array)$organization->getOrganizationIdsForQuery($enum) as $orgId) {
            $ids[] = intval($orgId);
        }
        foreach (RoleGroup::getHasPermissionRoleGroup(null, array_values(array_unique($ids))) as $roleGroup) {
            if (static::givesSystem($roleGroup)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the role group gives the system permission.
     */
    public static function givesSystem(RoleGroup $roleGroup): bool
    {
        return MemberDirectory::givesSystem($roleGroup);
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
        $found = getModelName($tableName)::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => intval($id))->all();
        $missing = array_values(array_diff($ids, $found));
        if (count($missing) > 0) {
            return [[], abortJson(400, exmtrans('api.errors.members_not_found', ['name' => $name, 'ids' => implode(',', $missing)]), ErrorCode::DATA_NOT_FOUND())];
        }
        return [$ids, null];
    }

    protected static function pivotTable(): string
    {
        return MemberDirectory::pivotTable();
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    protected static function userIdsOf(int $orgId): \Illuminate\Support\Collection
    {
        return MemberDirectory::userIdsOf($orgId);
    }

    protected static function findValue(string $tableName, mixed $id): ?CustomValue
    {
        return MemberDirectory::findValue($tableName, $id);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function roleGroupItem(RoleGroup $roleGroup, bool $members): array
    {
        return MemberDirectory::roleGroupItem($roleGroup, $members);
    }
}

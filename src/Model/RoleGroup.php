<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property mixed $role_group_view_name
 * @property mixed $role_group_order
 * @property mixed $role_group_name
 * @property mixed $description
 * @phpstan-consistent-constructor
 */
class RoleGroup extends ModelBase
{
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;


    // @phpstan-ignore-next-line
    public static $templateItems = [
        'excepts' => [],
        'uniqueKeys' => ['role_group_name'],
        'langs' => [
            'keys' => ['role_group_name'],
            'values' => ['role_group_view_name', 'description'],
        ],
        'children' =>[
            'role_group_permissions' => RoleGroupPermission::class,
        ],
    ];


    /**
     * @return HasMany<RoleGroupPermission, $this>
     */
    public function role_group_permissions(): HasMany
    {
        return $this->hasMany(RoleGroupPermission::class, 'role_group_id');
    }


    /**
     * @return HasMany<RoleGroupUserOrganization, $this>
     */
    public function role_group_user_organizations(): HasMany
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id');
    }


    /**
     * @return HasMany<RoleGroupUserOrganization, $this>
     */
    public function role_group_users(): HasMany
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'user');
    }


    /**
     * @return HasMany<RoleGroupUserOrganization, $this>
     */
    public function role_group_organizations(): HasMany
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'organization');
    }

    /**
     * Get HasPermissionRoleGroup.
     * Check as user, and organization.
     *
     * @return \Illuminate\Support\Collection
     */

    // @phpstan-ignore-next-line
    public static function getHasPermissionRoleGroup($user_id, $organization_ids, $checkContainJointdOrgs = false)
    {
        // the user and the organizations themselves (the permissions of a request, ISS02880): the
        // ids of the groups from the table of the members, not every group with every member
        if (!$checkContainJointdOrgs) {
            return static::roleGroupsOfMembers($user_id, $organization_ids);
        }

        // get all permissons for system. --------------------------------------------------

        // @phpstan-ignore-next-line
        return static::allRecordsCache(function ($role_group) use ($user_id, $organization_ids, $checkContainJointdOrgs) {
            $user_orgs = array_get($role_group, SystemTableName::ROLE_GROUP_USER_ORGANIZATION);
            if (is_nullorempty($user_orgs)) {
                return false;
            }

            if ($user_orgs->contains(function ($user_org) use ($user_id, $organization_ids, $checkContainJointdOrgs) {
                if (!is_nullorempty($user_id)) {
                    if ($user_org->role_group_user_org_type == SystemTableName::USER && in_array($user_org->role_group_target_id, (array)$user_id)) {
                        return true;
                    }
                }

                if (!is_nullorempty($organization_ids) && $user_org->role_group_user_org_type == SystemTableName::ORGANIZATION) {
                    if (!$checkContainJointdOrgs && in_array($user_org->role_group_target_id, (array)$organization_ids)) {
                        return true;
                    } elseif ($checkContainJointdOrgs) {
                        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_role_group(), JoinedOrgFilterType::ALL);
                        foreach ((array)$organization_ids as $organization_id) {
                            // ge check contains parent and child organizaions.
                            $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel($organization_id);


                            // @phpstan-ignore-next-line
                            $targetOrgIds = $org->getOrganizationIdsForQuery($enum);
                            if (in_array($organization_id, $targetOrgIds)) {
                                return true;
                            }
                        }
                    }
                }

                return false;
            })) {
                return true;
            }

            return false;
        }, false, [SystemTableName::ROLE_GROUP_PERMISSION, SystemTableName::ROLE_GROUP_USER_ORGANIZATION]);
    }

    /**
     * The role groups of a user or of organizations (as getHasPermissionRoleGroup without
     * $checkContainJointdOrgs), with their permissions. Once for a request.
     *
     * @param mixed $user_id
     * @param mixed $organization_ids
     * @return \Illuminate\Support\Collection<int, RoleGroup>
     */
    protected static function roleGroupsOfMembers($user_id, $organization_ids): \Illuminate\Support\Collection
    {
        $userIds = is_nullorempty($user_id) ? [] : array_values((array)$user_id);
        $orgIds = is_nullorempty($organization_ids) ? [] : array_values((array)$organization_ids);
        if (count($userIds) === 0 && count($orgIds) === 0) {
            return collect();
        }
        $key = 'role_groups_of_members_' . md5(json_encode([$userIds, $orgIds]) ?: '');
        return System::requestSession($key, function () use ($userIds, $orgIds) {
            $ids = RoleGroupUserOrganization::query()
                ->where(function ($query) use ($userIds, $orgIds) {
                    if (count($userIds) > 0) {
                        $query->orWhere(function ($q) use ($userIds) {
                            $q->where('role_group_user_org_type', SystemTableName::USER)->whereIn('role_group_target_id', $userIds);
                        });
                    }
                    if (count($orgIds) > 0) {
                        $query->orWhere(function ($q) use ($orgIds) {
                            $q->where('role_group_user_org_type', SystemTableName::ORGANIZATION)->whereIn('role_group_target_id', $orgIds);
                        });
                    }
                })
                ->distinct()
                ->pluck('role_group_id')
                ->all();
            if (count($ids) === 0) {
                return collect();
            }
            return static::query()->with(SystemTableName::ROLE_GROUP_PERMISSION)->whereIn('id', $ids)->get();
        });
    }

    protected static function boot()
    {
        parent::boot();

        // delete event
        static::deleting(function ($model) {
            $model->role_group_permissions()->delete();
            $model->role_group_user_organizations()->delete();
        });

        if (config('exment.sort_role_group_by_order', false)) {
            static::addGlobalScope(new OrderScope('role_group_order'));
        }
    }
}

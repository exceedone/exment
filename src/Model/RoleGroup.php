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

    public function role_group_permissions(): HasMany
    {
        return $this->hasMany(RoleGroupPermission::class, 'role_group_id');
    }

    public function role_group_user_organizations(): HasMany
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id');
    }

    public function role_group_users(): HasMany
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'user');
    }

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
    public static function getHasPermissionRoleGroup($user_id, $organization_ids, $checkContainJointdOrgs = false)
    {
        // get all permissons for system. --------------------------------------------------
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

                            /** @phpstan-ignore-next-line  $org uses OrganizationTrait */
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

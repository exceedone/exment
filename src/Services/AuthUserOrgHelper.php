<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Widgets\ModalForm;

/**
 * Role, user , organization helper
 */
class AuthUserOrgHelper
{
    /**
     * get organiztions who has roles.
     * this function is called from custom value role
     */
    // getRoleUserOrgQuery
    public static function getRoleOrganizationQuery($target_table, $tablePermission = null)
    {
        if (is_null($target_table)) {
            return [];
        }
        if (!System::organization_available()) {
            return [];
        }

        $all = false;
        if ($target_table->allUserAccessable()) {
            $all = true;
        } else {
            $target_table = CustomTable::getEloquent($target_table);

            // check request session
            $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_ORGS, $target_table->id);
            
            // if set $tablePermission, always call
            if (isset($tablePermission) || is_null($target_ids = System::requestSession($key))) {
                // get organiztion ids
                $target_ids = static::getRoleUserOrgId($target_table, SystemTableName::ORGANIZATION, $tablePermission);
                if (!isset($tablePermission)) {
                    System::requestSession($key, $target_ids);
                }
            }
        }

        // return target values
        $builder = getModelName(SystemTableName::ORGANIZATION)::query();
        if (!$all) {
            $builder->whereIn('id', $target_ids);
        }

        return $builder;
    }
    
    /**
     * get users who has roles.
     * and get users joined parent or children organizations
     * this function is called from custom value role
     */
    // getRoleUserOrgQuery
    public static function getRoleUserQueryTable($target_table, $tablePermission = null)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);
        
        $all = false;
        if ($target_table->allUserAccessable()) {
            $all = true;
        } else {
            // check request session
            $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_ACCRSSIBLE_USERS, $target_table->id);
            // if set $tablePermission, always call
            if (isset($tablePermission) || is_null($target_ids = System::requestSession($key))) {
                // get user ids
                $target_ids = static::getRoleUserOrgId($target_table ?? [], SystemTableName::USER, $tablePermission);

                if (System::organization_available()) {
                    // and get authoritiable organization
                    $organizations = static::getRoleOrganizationQuery($target_table)
                        ->get() ?? [];
                    foreach ($organizations as $organization) {
                        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                        $relatedOrgs = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel()->with('users')->find($organization->getOrganizationIds($enum));

                        foreach ($relatedOrgs as $related_organization) {
                            foreach ($related_organization->users as $user) {
                                $target_ids[] = $user->id;
                            }
                        }
                    }
                }

                if (!isset($tablePermission)) {
                    System::requestSession($key, $target_ids);
                }
            }
        }
    
        $target_ids = array_unique($target_ids);
        // return target values
        $builder = getModelName(SystemTableName::USER)::query();
        if (!$all) {
            $builder->whereIn('id', $target_ids);
        }

        return $builder;
    }

    /**
     * get all users who can access custom_value.
     * *key:custom_value
     * @return CustomValue users who can access custom_value.
     */
    public static function getRoleUserQueryValue($custom_value, $tablePermission = null)
    {
        // get custom_value's users
        $target_ids = [];
        
        // check request session
        $key = sprintf(Define::SYSTEM_KEY_SESSION_VALUE_ACCRSSIBLE_USERS, $custom_value->custom_table->id, $custom_value->id);
        // if set $tablePermission, always call
        if (isset($tablePermission) || is_null($target_ids = System::requestSession($key))) {
            $target_ids = array_merge(
                $custom_value->value_authoritable_users()->pluck('authoritable_target_id')->toArray(),
                []
            );

            // get custom_value's organizations
            if (System::organization_available()) {
                // and get authoritiable organization
                $organizations = $custom_value->value_authoritable_organizations()
                    ->with('users')
                    ->get() ?? [];
                $tablename = getDBTableName(SystemTableName::USER);
                foreach ($organizations as $organization) {
                    $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                    $relatedOrgs = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel()->with('users')->find($organization->getOrganizationIds($enum));

                    foreach ($relatedOrgs as $related_organization) {
                        $target_ids = array_merge(
                            $related_organization->users()->pluck("$tablename.id")->toArray(),
                            $target_ids
                        );
                    }
                }
            }

            // get custom table's user ids
            $target_ids = array_merge(
                static::getRoleUserQueryTable($custom_value->custom_table, $tablePermission)->pluck('id')->toArray(),
                $target_ids
            );

            if (!isset($tablePermission)) {
                System::requestSession($key, $target_ids);
            }
        }
    
        // return target values
        $builder = getModelName(SystemTableName::USER)::query();
        $builder->whereIn('id', $target_ids);
        return $builder;
    }
    
    /**
     * get organizations as eloquent model
     * @return mixed
     */
    public static function getOrganizations($withUsers = false)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        $query = static::getOrganizationQuery();
        $deeps = intval(config('exment.organization_deeps', 4));
        
        if ($withUsers) {
            $query->with('users');
        }

        $orgs = $query->get();
        return $orgs;
    }

    /**
     * get organization ids
     * @return mixed
     */
    public static function getOrganizationIds($onlyUserJoined = false, $filterType = JoinedOrgFilterType::ALL, $targetUserId = null)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        
        $orgs = static::getOrganizations(true);
        $org_flattens = [];

        // if get only user joined organization, call function
        if ($onlyUserJoined) {
            foreach ($orgs as $org) {
                static::setFlattenOrganizationsUserJoins($org, $org_flattens, $filterType, false, $targetUserId);
            }
        } else {
            static::setFlattenOrganizations($org, $org_flattens, $onlyUserJoined);
        }

        return collect($org_flattens)->map(function ($org_flatten) {
            return $org_flatten->id;
        })->toArray();
    }

    public static function getOrganizationQuery()
    {
        // get organization ids.
        $db_table_name_organization = getDBTableName(SystemTableName::ORGANIZATION);
        $parent_org_index_name = CustomColumn::getEloquent('parent_organization', CustomTable::getEloquent(SystemTableName::ORGANIZATION))->getIndexColumnName();
        $deeps = intval(config('exment.organization_deeps', 4));
        
        // create with
        $withs = str_repeat('children_organizations.', $deeps);

        $modelname = getModelName(SystemTableName::ORGANIZATION);
        $query = $modelname::query();
        $query->with(trim($withs, '.'));
        $query->whereNull($modelname::getParentOrgIndexName());
        return $query;
    }

    /**
     * Get User, org, role group form
     *
     * @return void
     */
    public static function getUserOrgModalForm($custom_table = null, $value = [], $options = [])
    {
        $options = array_merge([
            'prependCallback' => null
        ], $options);
        
        $form = new ModalForm();

        if (isset($options['prependCallback'])) {
            $options['prependCallback']($form);
        }

        list($users, $ajax) = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptionsAndAjaxUrl([
            'display_table' => $custom_table,
            'selected_value' => array_get($value, SystemTableName::USER),
        ]);

        // select target users
        $form->multipleSelect('modal_' . SystemTableName::USER, exmtrans('menu.system_definitions.user'))
            ->options($users)
            ->ajax($ajax)
            ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
            ->default(array_get($value, SystemTableName::USER));

        if (System::organization_available()) {
            list($organizations, $ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptionsAndAjaxUrl([
                'display_table' => $custom_table,
                'selected_value' => array_get($value, SystemTableName::ORGANIZATION),
            ]);
                
            $form->multipleSelect('modal_' . SystemTableName::ORGANIZATION, exmtrans('menu.system_definitions.organization'))
                ->options($organizations)
                ->ajax($ajax)
                ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
                ->default(array_get($value, SystemTableName::ORGANIZATION));
        }

        return $form;
    }

    protected static function setFlattenOrganizations($orgs, &$org_flattens)
    {
        foreach ($orgs as $org) {
            // if exisis, return
            if (static::isAlreadySetsOrg($org, $org_flattens)) {
                return false;
            }
            $org_flattens[] = $org;

            if ($org->hasChildren()) {
                static::setFlattenOrganizations($org->children_organizations, $org_flattens);
            }
        }
    }

    /**
     * filter organizaion only user joined.
     */
    protected static function setFlattenOrganizationsUserJoins($org, &$org_flattens, $filterType = JoinedOrgFilterType::ONLY_JOIN, $parentJoin = false, $targetUserId = null)
    {
        // if exisis, return
        if (static::isAlreadySetsOrg($org, $org_flattens)) {
            return false;
        }

        if(!isset($targetUserId)){
            $targetUserId = \Exment::user()->base_user_id;
        }

        // first, check this user joins this org
        // if only user joined, check user id, if not exists, continue;
        $join = true;

        // if user joins parent organization, set join is true
        if ($parentJoin && JoinedOrgFilterType::isGetUpper($filterType)) {
            $join = true;
        }
        ///// check user join org.
        // if not joins users, set join is false
        elseif (!isset($org->users)) {
            $join = false;
        }
        // not match id, set id is false
        elseif ($org->users->filter(function ($user) use($targetUserId) {
            return $user->id == $targetUserId;
        })->count() == 0) {
            $join = false;
        }

        if ($join) {
            $org_flattens[] = $org;
        }

        // second, user joins children's org check childrens
        $result = $join;
        if ($org->hasChildren()) {
            foreach ($org->children_organizations as $children_organization) {
                // if, user joins some children organizations, join is true.
                if (static::setFlattenOrganizationsUserJoins($children_organization, $org_flattens, $filterType, $join, $targetUserId)) {
                    $result = true;

                    // if not sets this org, set this org too.
                    if (JoinedOrgFilterType::isGetDowner($filterType) && !static::isAlreadySetsOrg($org, $org_flattens)) {
                        $org_flattens[] = $org;
                    }
                }
            }
        }
        return $result;
    }

    protected static function isAlreadySetsOrg($org, &$org_flattens)
    {
        if (collect($org_flattens)->filter(function ($org_flatten) use ($org) {
            return $org_flatten->id == $org->id;
        })->count() > 0) {
            return true;
        }
        return false;
    }

    /**
     * get users or organizaitons who can access table.
     *
     * @param CustomTable $target_table access table.
     * @param array $related_types "user" or "organization"
     */
    protected static function getRoleUserOrgId($target_table, $related_type, $tablePermission = null)
    {
        $target_table = CustomTable::getEloquent($target_table);
        
        // Get role group contains target_table's
        $roleGroups = RoleGroup::whereHas('role_group_permissions', function ($query) use ($target_table) {
            $query->where(function ($query) use ($target_table) {
                $query->orWhere(function ($query) {
                    $query->where('role_group_permission_type', RoleType::SYSTEM);
                });
                $query->orWhere(function ($query) use ($target_table) {
                    $query->where('role_group_permission_type', RoleType::TABLE)
                        ->where('role_group_target_id', $target_table->id);
                });
            });
        })->with(['role_group_user_organizations', 'role_group_permissions'])->get();

        $target_ids = collect();
        foreach ($roleGroups as $roleGroup) {
            // check permission
            if (!$roleGroup->role_group_permissions->contains(function ($role_group_permission) use ($target_table, $tablePermission) {
                // check as system
                if ($role_group_permission->role_group_permission_type == RoleType::SYSTEM) {
                    $tablePermission = [Permission::SYSTEM, Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_EDIT_ALL];
                }
                // check as table
                else {
                    // not match table, return false
                    if ($target_table->id != $role_group_permission->role_group_target_id) {
                        return false;
                    }
                }

                // check as table
                if (!isset($tablePermission)) {
                    $tablePermission = Permission::AVAILABLE_ACCESS_CUSTOM_VALUE;
                }
                
                // check contains $tablePermission in $role_group_permission
                return collect($tablePermission)->contains(function ($p) use ($role_group_permission) {
                    return in_array($p, $role_group_permission->permissions);
                });
            })) {
                continue;
            }

            foreach ($roleGroup->role_group_user_organizations as $role_group_user_organization) {
                // merge users from $role_group_user_organization
                if ($role_group_user_organization->role_group_user_org_type != $related_type) {
                    continue;
                }
                $target_ids = $target_ids->merge($role_group_user_organization->role_group_target_id);
            }
        }

        // set system user if $related_type is USER
        if ($related_type == SystemTableName::USER) {
            $target_ids = $target_ids->merge(System::system_admin_users() ?? []);
        }

        return $target_ids->filter()->unique()->toArray();
    }
}

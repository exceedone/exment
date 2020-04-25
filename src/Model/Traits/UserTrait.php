<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

trait UserTrait
{
    /**
     * get login users.
     * Why "hasMany" not "hasOne" is It can be logged in by multiple providers.
     */
    public function login_users()
    {
        return $this->hasMany(Model\LoginUser::class, "base_user_id");
    }

    /**
     * get login user.
     * only support login provider is null (default)
     */
    public function login_user()
    {
        return $this->hasOne(Model\LoginUser::class, "base_user_id")->whereNull('login_provider');
    }

    public function user_setting()
    {
        return $this->hasOne(Model\UserSetting::class, "user_id");
    }
    
    /**
     * get organizations that this_user joins.
     * @return mixed
     */
    public function getOrganizationIds($filterType = JoinedOrgFilterType::ALL)
    {
        // if system doesn't use organization, return empty array.
        if (!System::organization_available()) {
            return [];
        }
        return AuthUserOrgHelper::getOrganizationIds($filterType, $this->id);
    }

    /**
     * Get organizations user joined.
     * * ONLY JOIN. not contains upper and downer.
     *
     * @return void
     */
    public function belong_organizations()
    {
        $db_table_name_pivot = Model\CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        return $this->{$db_table_name_pivot}();
    }
    
    /**
     * get role_group user or org joined.
     *
     * @return void
     */
    public function belong_role_groups()
    {
        return Model\RoleGroup::whereHas('role_group_users', function ($query) {
            $query->where('role_group_target_id', $this->id);
        })->get();
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function disabled_delete_trait()
    {
        // only administrator can delete and edit administrator record
        if (!\Exment::user()->isAdministrator() && isset($this->login_user) && $this->login_user->isAdministrator()) {
            return true;
        }
        // cannnot delete myself
        if (\Exment::user()->getUserId() == $this->id) {
            return true;
        }
    }

    /**
     * Get avatar
     *
     * @return void
     */
    public function getDisplayAvatarAttribute()
    {
        // get login user
        $login_user = $this->login_users->first(function ($login_user) {
            return isset($login_user->avatar);
        });

        if (isset($login_user)) {
            return $login_user->display_avatar;
        }

        // get default avatar
        return asset(Define::USER_IMAGE_LINK);
    }
    
    public function isAdministrator()
    {
        return collect(System::system_admin_users())->contains($this->id);
    }
    
    /**
     * Get User Model's ID
     * "This function name defines Custom value's user and login user. But this function always return Custom value's user
     *
     * @return string|int
     */
    public function getUserId(){
        return $this->id;
    }

}

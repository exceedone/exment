<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class RoleGroup extends ModelBase
{
    public function role_group_permissions()
    {
        return $this->hasMany(RoleGroupPermission::class, 'role_group_id');
    }

    public function role_group_user_organizations()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id');
    }
    
    public function role_group_users()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'user');
    }

    public function role_group_organizations()
    {
        return $this->hasMany(RoleGroupUserOrganization::class, 'role_group_id')
            ->where('role_group_user_org_type', 'organization');
    }
}

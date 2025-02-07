<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;

class RoleGroupPermissionRoleProvider extends RoleGroupPermissionProvider
{
    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_role';
    }
    
    protected function setRoleTypeFilter(&$query)
    {
        $query->where('role_group_permission_type', RoleType::SYSTEM)
            ->where('role_group_target_id', 1);
    }
    
    protected function getRoleGroupType(): RoleGroupType
    {
        return RoleGroupType::ROLE_GROUP();
    }
}

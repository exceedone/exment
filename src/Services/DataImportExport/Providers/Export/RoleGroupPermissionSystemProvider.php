<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\RoleGroupType;

class RoleGroupPermissionSystemProvider extends RoleGroupPermissionProvider
{
    /**
     * get data name
     */
    // @phpstan-ignore-next-line
    public function name()
    {
        return 'role_group_permission_system';
    }
    
    // @phpstan-ignore-next-line
    protected function setRoleTypeFilter(&$query)
    {
        $query->where('role_group_permission_type', RoleType::SYSTEM)
            ->where('role_group_target_id', 0);
    }
    
    protected function getRoleGroupType(): RoleGroupType
    {
        return RoleGroupType::SYSTEM();
    }
}

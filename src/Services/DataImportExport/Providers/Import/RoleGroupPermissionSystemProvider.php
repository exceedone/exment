<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\JoinedMultiUserFilterType;
use Exceedone\Exment\Model\System;

class RoleGroupPermissionSystemProvider extends RoleGroupPermissionProvider
{
    public function __construct()
    {
        $this->role_group_target_id = 0;
        $this->permission_keys = Permission::SYSTEM_ROLE_PERMISSIONS;
        if (System::filter_multi_user() != JoinedMultiUserFilterType::NOT_FILTER) {
            $this->permission_keys[] = Permission::FILTER_MULTIUSER_ALL;
        }
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_system';
    }
    
}

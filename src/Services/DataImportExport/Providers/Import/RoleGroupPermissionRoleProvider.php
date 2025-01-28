<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;

class RoleGroupPermissionRoleProvider extends RoleGroupPermissionProvider
{
    public function __construct()
    {
        $this->role_group_target_id = 1;
        $this->permission_keys = Permission::ROLE_GROUP_ROLE_PERMISSION;
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_role';
    }
}

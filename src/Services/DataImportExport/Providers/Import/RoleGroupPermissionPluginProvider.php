<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\Plugin;

class RoleGroupPermissionPluginProvider extends RoleGroupPermissionProvider
{
    public function __construct($args = [])
    {
        $this->role_group_permission_type = RoleType::PLUGIN;
        $this->permission_keys = Permission::ROLE_GROUP_PLUGIN_PERMISSION;
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_plugin';
    }

    /**
     * add data row validate rules for each role type
     * 
     * @param $rules
     */
    protected function addValidateDataRule(&$rules) : void
    {
        $rules['role_group_target_id'] = 'required|exists:' . Plugin::make()->getTable() . ',id';
    }
}

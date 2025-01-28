<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\Plugin;

class RoleGroupPermissionPluginProvider extends RoleGroupPermissionProvider
{
    public function __construct()
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
    protected function addValidateTypeRules(&$rules) : void
    {
        $model = new Plugin();
        $rules['role_group_target_id'] = 'required|exists:' . $model->getTable() . ',id';
    }

    /**
     * validate data row by ex rules
     * 
     * @param array $data
     * @param int $line_no
     * @param array $errors
     */
    protected function validateExtraRules($data, $line_no, &$errors) : void
    {
        $role_group_target_id = array_get($data, 'role_group_target_id');
        $plugin_access = array_get($data, 'permissions:plugin_access');
        $plugin = Plugin::find($role_group_target_id);
        $enabledPluginAccess = collect($plugin->plugin_types)->contains(function ($plugin_type) {
            return in_array($plugin_type, PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE());
        });
        if (!$enabledPluginAccess && boolval($plugin_access)) {
            $errors[] = sprintf(
                exmtrans('custom_value.import.import_error_format_sheet'), 
                $this->name(), 
                ($line_no+1), 
                exmtrans('role_group.error.cannot_plugin_access_permission')
            );
        }
    }
}

<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

class RoleGroupPermissionTableProvider extends RoleGroupPermissionProvider
{
    public function __construct()
    {
        $this->role_group_permission_type = RoleType::TABLE;
        $this->permission_keys = Permission::TABLE_ROLE_PERMISSION;
    }

    /**
     * get data name
     */
    public function name()
    {
        return 'role_group_permission_table';
    }
    

    /**
     * add data row validate rules for each role type
     * 
     * @param $rules
     */
    protected function addValidateTypeRules(&$rules) : void
    {
        $ids = CustomTable::whereIn('table_name', SystemTableName::SYSTEM_TABLE_NAME_MASTER())->pluck('id')->toArray();        
        $model = new CustomTable();
        $rules['role_group_target_id'] = 'required|exists:' . $model->getTable() . ',id|not_in:'. implode(',', $ids);
    }
}

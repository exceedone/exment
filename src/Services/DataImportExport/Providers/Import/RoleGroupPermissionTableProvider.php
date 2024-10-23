<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\CustomTable;

class RoleGroupPermissionTableProvider extends RoleGroupPermissionProvider
{
    public function __construct($args = [])
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
    protected function addValidateDataRule(&$rules) : void
    {
        $rules['role_group_target_id'] = 'required|exists:' . CustomTable::make()->getTable() . ',id';
    }
}

<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;

class RoleGroupUserOrganization extends ModelBase
{
    /**
     * Get belonged users
     *
     * @return Collection
     */
    public function getUsers(){
        if($this->role_group_user_org_type == SystemTableName::USER){
            return collect(CustomTable::getEloquent(SystemTableName::USER)->getValueModel($this->role_group_target_id));
        }
        elseif($this->role_group_user_org_type == SystemTableName::ORGANIZATION){
            return CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel($this->role_group_target_id)->users;
        }
        return collect();
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function deleteRoleGroupUserOrganization($custom_value){
        $custom_table = $custom_value->custom_table;
        $table_name = $custom_table->table_name;

        
        if (!in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            return;
        }

        static::query()
        ->where('role_group_user_org_type', $table_name)
        ->where('role_group_target_id', $custom_value->id)
        ->delete();
    }
}

<?php

namespace Exceedone\Exment\Enums;

class RoleGroupType extends EnumBase
{
    const SYSTEM = "system";
    const TABLE = "table";
    const MASTER = "master";
    const ROLE_GROUP = "role_group";
    
    public function getRoleGroupOptions(){
        $permissions = $this->getRoleGroupPermissions();
        return collect($permissions)->mapWithKeys(function($permission){
            return [$permission => exmtrans("role_group.role_type_option_{$this->lowerKey()}.$permission.label")];
        });
    }
    
    public function getRoleGroupHelps(){
        $permissions = $this->getRoleGroupPermissions();
        return collect($permissions)->mapWithKeys(function ($permission) {
            return [$permission => exmtrans("role_group.role_type_option_{$this->lowerKey()}.$permission.help")];
        });
    }

    protected function getRoleGroupPermissions(){
        switch($this->lowerKey()){
            case self::SYSTEM()->lowerKey():
                return Permission::SYSTEM_ROLE_PERMISSIONS;
            case self::TABLE()->lowerKey():
                return Permission::TABLE_ROLE_PERMISSION;
            case self::MASTER()->lowerKey():
                return Permission::MASTER_ROLE_PERMISSION;
            case self::ROLE_GROUP()->lowerKey():
                return Permission::ROLE_GROUP_ROLE_PERMISSION;
        }

    }
}

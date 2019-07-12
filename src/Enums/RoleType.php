<?php

namespace Exceedone\Exment\Enums;

class RoleType extends EnumBase
{
    const SYSTEM = 0;
    const TABLE = 1;
    const VALUE = 2;
    const PLUGIN = 3;
    const MASTER = 4;

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
        }

    }
}

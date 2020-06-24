<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

/**
 * Role Group Type Difinition.
 *
 * @method static ErrorCode SYSTEM()
 * @method static ErrorCode TABLE()
 * @method static ErrorCode MASTER()
 * @method static ErrorCode PLUGIN()
 * @method static ErrorCode ROLE_GROUP()
 */
class RoleGroupType extends EnumBase
{
    const SYSTEM = "system";
    const TABLE = "table";
    const MASTER = "master";
    const PLUGIN = "plugin";
    const ROLE_GROUP = "role_group";
    
    public function getRoleGroupOptions()
    {
        $permissions = $this->getRoleGroupPermissions();
        return collect($permissions)->mapWithKeys(function ($permission) {
            return [$permission => exmtrans("role_group.role_type_option_{$this->lowerKey()}.$permission.label")];
        });
    }
    
    public function getRoleGroupHelps()
    {
        $permissions = $this->getRoleGroupPermissions();
        return collect($permissions)->mapWithKeys(function ($permission) {
            return [$permission => exmtrans("role_group.role_type_option_{$this->lowerKey()}.$permission.help")];
        });
    }

    protected function getRoleGroupPermissions()
    {
        switch ($this->lowerKey()) {
            case self::SYSTEM()->lowerKey():
                $permissions = Permission::SYSTEM_ROLE_PERMISSIONS;
                if(System::filter_multi_user() != JoinedMultiUserFilterType::NOT_FILTER){
                    $permissions[] = Permission::FILTER_MULTIUSER_ALL;
                }
                return $permissions;
            case self::TABLE()->lowerKey():
                return Permission::TABLE_ROLE_PERMISSION;
            case self::MASTER()->lowerKey():
                return Permission::MASTER_ROLE_PERMISSION;
            case self::ROLE_GROUP()->lowerKey():
                return Permission::ROLE_GROUP_ROLE_PERMISSION;
            case self::PLUGIN()->lowerKey():
                return Permission::ROLE_GROUP_PLUGIN_PERMISSION;
        }
    }
}

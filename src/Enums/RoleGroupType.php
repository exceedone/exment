<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

/**
 * Role Group Type Difinition.
 *
 * @method static RoleGroupType SYSTEM()
 * @method static RoleGroupType TABLE()
 * @method static RoleGroupType MASTER()
 * @method static RoleGroupType PLUGIN()
 * @method static RoleGroupType ROLE_GROUP()
 */
class RoleGroupType extends EnumBase
{
    public const SYSTEM = "system";
    public const TABLE = "table";
    public const MASTER = "master";
    public const PLUGIN = "plugin";
    public const ROLE_GROUP = "role_group";

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
                if (System::filter_multi_user() != JoinedMultiUserFilterType::NOT_FILTER) {
                    $permissions[] = Permission::FILTER_MULTIUSER_ALL;
                }
                return $permissions;
            case self::TABLE()->lowerKey():
                $permissions = Permission::TABLE_ROLE_PERMISSION;
                if (!boolval(System::publicform_available())) {
                    $permissions = collect($permissions)->filter(function ($permission) {
                        return !in_array($permission, [Permission::CUSTOM_FORM_PUBLIC]);
                    })->toArray();
                }
                return $permissions;
            case self::MASTER()->lowerKey():
                return Permission::MASTER_ROLE_PERMISSION;
            case self::ROLE_GROUP()->lowerKey():
                return Permission::ROLE_GROUP_ROLE_PERMISSION;
            case self::PLUGIN()->lowerKey():
                return Permission::ROLE_GROUP_PLUGIN_PERMISSION;
        }
    }
}

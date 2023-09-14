<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

/**
 * @property mixed $role_group_user_org_type
 * @property mixed $role_group_target_id
 * @property mixed $role_group_id
 * @phpstan-consistent-constructor
 */
class RoleGroupUserOrganization extends ModelBase
{
    use Traits\ClearCacheTrait;

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */
    public static function deleteRoleGroupUserOrganization($custom_value)
    {
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

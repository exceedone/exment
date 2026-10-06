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
     * Scope: only rows whose target (user / organization) still exists and is not soft-deleted.
     *
     * Rows of this table are intentionally kept while the target is only soft-deleted
     * (they are removed on permanent delete only, see CustomValue::deleteRelationValues),
     * so anything that counts or lists the *current* members must use this scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $role_group_user_org_type SystemTableName::USER or SystemTableName::ORGANIZATION
     * @return \Illuminate\Database\Eloquent\Builder
     */
    // @phpstan-ignore-next-line
    public function scopeWhereTargetNotDeleted($query, string $role_group_user_org_type)
    {
        $db_table_name = getDBTableName($role_group_user_org_type);

        return $query->where($query->qualifyColumn('role_group_user_org_type'), $role_group_user_org_type)
            ->whereIn($query->qualifyColumn('role_group_target_id'), function ($sub) use ($db_table_name) {
                $sub->select("{$db_table_name}.id")
                    ->from($db_table_name)
                    ->whereNull("{$db_table_name}.deleted_at");
            });
    }

    /**
     * Delete Custom Value Authoritable after custom value save
     *
     * @return void
     */

    // @phpstan-ignore-next-line
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

<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

class CustomValueModelScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param Builder $builder
     * @param CustomValue $model
     * @return void
     * @throws \Exception
     */
    public function apply(Builder $builder, Model $model)
    {
        $table_name = $model->custom_table->table_name;
        $db_table_name = getDBTableName($table_name);

        // get user info
        $user = \Exment::user();
        // if not have, check as login
        if (!isset($user)) {
            // no access role
            //throw new \Exception;

            // set no filter. Because when this function called, almost after login or pass oauth authonize.
            // if throw exception, Cannot execute batch.
            return;
        }

        // if system administrator user, return
        if ($user->isAdministrator()) {
            return;
            // if user can access list, return
        }
        if ($table_name == SystemTableName::USER) {
            AuthUserOrgHelper::filterUserOnlyJoin($builder, $user, $db_table_name);
        }

        // organization
        elseif ($table_name == SystemTableName::ORGANIZATION) {
            AuthUserOrgHelper::filterOrganizationOnlyJoin($builder, $user, $db_table_name);
        }

        // Add document skip logic
        elseif ($table_name == SystemTableName::DOCUMENT) {
            //TODO
            return;
        } elseif ($model->custom_table->hasPermission(Permission::AVAILABLE_ALL_CUSTOM_VALUE)) {
            return;
        }
        // if user has edit or view table
        elseif ($model->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            $builder->whereHas('custom_value_authoritables', function ($builder) use ($user) {
                // get only has role
                $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                $builder->whereInMultiple(
                    ['authoritable_user_org_type', 'authoritable_target_id'],
                    $user->getUserAndOrganizationIds($enum),
                    true
                );
            });
        }
        // if not role, set always false result.
        else {
            $builder->where("$db_table_name.id", '<', 0);
        }
    }
}

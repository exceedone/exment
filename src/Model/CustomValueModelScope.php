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
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
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
            // get only has role
            $builder
                ->where(function ($builder) use ($user) {
                    $builder->whereHas('value_authoritable_users', function ($q) use ($user) {
                        $q->where('authoritable_target_id', $user->getUserId());
                    })->orWhereHas('value_authoritable_organizations', function ($q) use ($user) {
                        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                        $q->whereIn('authoritable_target_id', $user->getOrganizationIds($enum));
                    });
                });
        }
        // if not role, set always false result.
        else {
            $builder->where('id', '<', 0);
        }
    }
}

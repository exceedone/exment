<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\RelationType;
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

        // check if inherit parent permissions and get parent table
        $inherit_parent = boolval($model->custom_table->getOption('inherit_parent_permission'));
        $parent_table = null;
        if ($inherit_parent) {
            $relation = CustomRelation::getRelationByChild($model->custom_table, RelationType::ONE_TO_MANY);
            if (!empty($relation)) {
                $parent_table = $relation->parent_custom_table;
            }
            if (empty($parent_table)) {
                $inherit_parent = false;
            }
        }

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
        } elseif ($inherit_parent && $parent_table->hasPermission(Permission::AVAILABLE_ALL_CUSTOM_VALUE)) {
            return;
        }
        // if user has edit or view table
        elseif ($model->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            // get only has role
            $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);

            $builder->where(function($builder) use($user, $enum, $db_table_name, $inherit_parent, $parent_table) {
                $builder->whereHas('custom_value_authoritables', function ($builder) use ($user, $enum) {
                    $builder->whereInMultiple(
                        ['authoritable_user_org_type', 'authoritable_target_id'],
                        $user->getUserAndOrganizationIds($enum),
                        true
                    );
                });
                if ($inherit_parent && $parent_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                    $builder->orWhere(function($builder) use ($user, $enum, $db_table_name) {
                        $builder->whereExists(function ($builder) use ($user, $enum, $db_table_name) {
                            $builder->select(\DB::raw(1))
                                ->from('custom_value_authoritables')
                                ->whereColumn('custom_value_authoritables.parent_id', "{$db_table_name}.parent_id")
                                ->whereColumn('custom_value_authoritables.parent_type', "{$db_table_name}.parent_type")
                                ->whereInMultiple(
                                    ['authoritable_user_org_type', 'authoritable_target_id'],
                                    $user->getUserAndOrganizationIds($enum),
                                    true
                                );
                        });
                    });
                }
            });
        }
        // if not role, set always false result.
        else {
            $builder->where("$db_table_name.id", '<', 0);
        }
    }
}

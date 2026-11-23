<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

class CustomValueModelScope implements Scope
{
    /**
     * The tables whose rows are attached to a record of any other table by
     * parent_type and parent_id, and follow the access to that record.
     *
     * @var array<int, string>
     */
    public const PARENT_ATTACHED_TABLES = [
        SystemTableName::COMMENT,
        SystemTableName::DOCUMENT,
    ];

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param Builder $builder
     * @param CustomValue $model
     * @return void
     * @throws \Exception
     */

    // @phpstan-ignore-next-line
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
            // console, queue and batch (backup, import, schedules): no user, all rows
            if (app()->runningInConsole()) {
                return;
            }
            // a web request without a user: the system tables only (the login reads the user
            // table before the login). Public forms run as their proxy user, not here.
            if (boolval($model->custom_table->system_flg)) {
                return;
            }
            $builder->whereRaw('1 = 0');
            return;
        }

        // if system administrator user, return
        if ($user->isAdministrator()) {
            return;
            // if user can access list, return
        }

        // A comment or a document hangs off another record, and is only for
        // those who can access that record. This comes on top of the table's
        // own permission below.
        if (in_array($table_name, static::PARENT_ATTACHED_TABLES, true)) {
            $this->applyParentAccessible($builder, $db_table_name);
        }

        if ($table_name == SystemTableName::USER) {
            AuthUserOrgHelper::filterUserOnlyJoin($builder, $user, $db_table_name);
        }

        // organization
        elseif ($table_name == SystemTableName::ORGANIZATION) {
            AuthUserOrgHelper::filterOrganizationOnlyJoin($builder, $user, $db_table_name);
        }

        // Documents get no filter of their own here, and the filter below is
        // not the one to reach for: a document never gets a row of its own in
        // custom_value_authoritables (SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY
        // lists it), so asking for a share here would hide every document from
        // everybody. What gates a document is the record it hangs off: see
        // applyParentAccessible() above.
        elseif ($table_name == SystemTableName::DOCUMENT) {
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

    /**
     * Keep only the rows whose parent record the login user can access.
     *
     * For the tables whose rows are attached to a record of any other table
     * by parent_type and parent_id (PARENT_ATTACHED_TABLES). The table's own
     * permission says nothing about the record a row hangs off - every user
     * may edit every comment as far as the comment table goes - so the row is
     * given the visibility of that record: the parent has to be in what the
     * user's own scope of the parent table returns, deleted records included.
     *
     * Left as they were: rows without a parent, rows whose parent_type is not
     * a table (any more), and rows whose parent record no longer exists at all.
     *
     * @param Builder<\Illuminate\Database\Eloquent\Model> $builder
     * @param string $db_table_name
     * @return void
     */
    protected function applyParentAccessible(Builder $builder, string $db_table_name)
    {
        // The tables the user cannot access all records of. "query" is the ids
        // the user can access, null if none.
        $restricteds = [];
        /** @var CustomTable $parent_table */
        foreach (CustomTable::allRecords() as $parent_table) {
            $parent_table_name = $parent_table->table_name;
            // never the attached tables themselves: their scope is this one.
            if (in_array($parent_table_name, static::PARENT_ATTACHED_TABLES, true)) {
                continue;
            }

            $parent_db_table_name = getDBTableName($parent_table);
            // no database table yet, so no record a row could hang off.
            if (!hasTable($parent_db_table_name)) {
                continue;
            }

            // Two answers apply() gives from the table permission alone, taken
            // here first so that the model of every table is not built for them.
            $is_user_org = in_array($parent_table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION], true);
            if (!$is_user_org && $parent_table->hasPermission(Permission::AVAILABLE_ALL_CUSTOM_VALUE)) {
                // all records
                continue;
            }
            if (!$is_user_org
                && !$parent_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)
                && !boolval($parent_table->getOption('inherit_parent_permission'))) {
                // no record
                $parent_query = null;
            } else {
                // Otherwise ask the parent table's own scope for this user. Soft deleted records are kept.
                $parent_query = $parent_table->getValueQuery()->withoutGlobalScope(SoftDeletingScope::class)->toBase();
                if (empty($parent_query->wheres) && empty($parent_query->joins)) {
                    // no filter: the user can access all records of this table.
                    continue;
                }
                $parent_query->select("$parent_db_table_name.id");
            }

            $restricteds[] = [
                'table_name' => $parent_table_name,
                'db_table_name' => $parent_db_table_name,
                'query' => $parent_query,
            ];
        }

        if (empty($restricteds)) {
            return;
        }

        $builder->where(function ($builder) use ($restricteds, $db_table_name) {
            // no parent
            $builder->whereNull("$db_table_name.parent_type")
                ->orWhereNull("$db_table_name.parent_id")
                // the parent's table has no filter for this user, or it is not a table
                ->orWhereNotIn("$db_table_name.parent_type", array_column($restricteds, 'table_name'));

            foreach ($restricteds as $restricted) {
                $builder->orWhere(function ($builder) use ($restricted, $db_table_name) {
                    $builder->where("$db_table_name.parent_type", $restricted['table_name'])
                        ->where(function ($builder) use ($restricted, $db_table_name) {
                            // the parent does not exist any more
                            $builder->whereNotExists(function ($query) use ($restricted, $db_table_name) {
                                $query->select(\DB::raw(1))
                                    ->from($restricted['db_table_name'])
                                    ->whereColumn($restricted['db_table_name'] . '.id', "$db_table_name.parent_id");
                            });
                            // or it is a record the user can access
                            if (!is_null($restricted['query'])) {
                                $builder->orWhereIn("$db_table_name.parent_id", $restricted['query']);
                            }
                        });
                });
            }
        });
    }
}

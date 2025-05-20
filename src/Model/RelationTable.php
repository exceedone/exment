<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Collection;

/**
 * RelationTable item for Search, linkage, ...
 * Management these....
 *
 * Manage relation and select table. Ex:
 *      ・Organization (n:n relation) user
 *      ・Organization (select table joined "customer" table) user
 *      ・Organization (select table joiner "estimate") estimate
 */
class RelationTable
{
    /**
     * Relation base table.
     *
     * @var CustomTable
     */
    public $base_table;

    /**
     * Relation Target table.
     * Default (almost), this table is CHILD relation or select table.
     * If summary only this table is PARENT relation or select table.
     *
     * @var CustomTable
     */
    public $table;

    /**
     * Search type
     *
     * @var int
     */
    public $searchType;

    /**
     * Select table's pivot column. Only use select table search.
     *
     * @var CustomColumn
     */
    public $selectTablePivotColumn;

    /**
     * Custom relation. Only use relation search.
     *
     * @var CustomRelation
     */
    public $relation;

    /**
     * table's unique name. If join, set database table and use this table name.
     *
     * @var string
     */
    public $tableUniqueName;

    /**
     * Sub query's callbacks. Use for summary.
     * If set, call for sub query select, group by etc.
     *
     * @var array
     */
    public $subQueryCallbacks = [];


    public function __construct(array $params = [])
    {
        $this->base_table = array_get($params, 'base_table');
        $this->table = array_get($params, 'table');
        $this->searchType = array_get($params, 'searchType');
        $this->selectTablePivotColumn = array_get($params, 'selectTablePivotColumn');
        $this->relation = CustomRelation::getEloquent(array_get($params, 'relation'));

        $this->tableUniqueName = short_uuid();
    }


    /**
     * Get children's relation tables list.(Contains select table)
     * It contains search_type(select_table, one_to_many, many_to_many)
     *
     * @param CustomTable $custom_table target custom table
     * @param boolean $checkPermission
     * @param array $options
     * @return \Illuminate\Support\Collection children relation(select_table, one_to_many, many_to_many)'s RleationTable info.
     */
    public static function getRelationTables($custom_table, $checkPermission = true, $options = [])
    {
        $options = array_merge(
            [
                'search_enabled_only' => true, // if true, filtering search enabled
                'get_child_relation_tables' => true, // if true, get relation tables joined to parent. Now only use summary view.
                'get_parent_relation_tables' => false, // if true, get relation tables joined to parent. Now only use summary view.
            ],
            $options
        );
        $custom_table = CustomTable::getEloquent($custom_table);

        // check already execute
        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES, $custom_table->table_name, strval($options['get_parent_relation_tables']));
        return System::requestSession($key, function () use ($custom_table, $options) {
            $results = collect();

            // Get only options get_child_relation_tables is true
            if (boolval($options['get_child_relation_tables'])) {
                // 1. Get custom columns as "select_table". They contains these columns matching them.
                $results = $results->merge(static::_getTablesSelectTable($custom_table, $options));

                // 2. Get relation tables.
                // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
                $results = $results->merge(static::_getTablesRelation($custom_table, $options));
            }

            // Only call get_parent_relation_tables option.
            if (boolval($options['get_parent_relation_tables'])) {
                // 3. Get custom columns as "select_table" to parent.
                $results = $results->merge(static::_getParentTablesSelectTable($custom_table, $options));

                // 4. Get relation tables to parent.
                $results = $results->merge(static::_getParentTablesRelation($custom_table, $options));
            }

            return $results;
        })->filter(function ($result) use ($checkPermission) {
            // if not role, continue
            if ($checkPermission && !$result->table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                return false;
            }

            return true;
        });
    }



    /**
     * Get relation table. Using view pivot table and column id.
     * *Alomose same func contains SearchService.*
     *
     * @param CustomColumn|string|int $custom_column target custom column
     * @param string|int|null $view_pivot_column_id
     * @param string|int|null $view_pivot_table_id
     * @return RelationTable|null
     */
    public static function getRelationTable($custom_column, $view_pivot_column_id, $view_pivot_table_id): ?RelationTable
    {
        if (is_nullorempty($view_pivot_column_id) || is_nullorempty($view_pivot_table_id)) {
            return null;
        }

        $options = [
            'search_enabled_only' => false,
            'get_child_relation_tables' => false,
            'get_parent_relation_tables' => true,
        ];
        $custom_column = CustomColumn::getEloquent($custom_column);
        if (!$custom_column) {
            return null;
        }
        $custom_table = CustomTable::getEloquent($custom_column->custom_table_id);
        if (!$custom_table) {
            return null;
        }
        $view_pivot_table = CustomTable::getEloquent($view_pivot_table_id);
        if (!$view_pivot_table) {
            return null;
        }

        // get relation tables. Base table is view_pivot_table(real base table).
        $relationTables = static::getRelationTables($view_pivot_table, false, $options);
        if (is_nullorempty($relationTables)) {
            return null;
        }

        // if only 1, return first.
        if ($relationTables->count() <= 1) {
            return $relationTables->first();
        }

        // get search type
        // If $view_pivot_column_id is not "parent_id", set searchtype is select_table
        if (!isMatchString($view_pivot_column_id, Define::PARENT_ID_NAME)) {
            $searchType = SearchType::SELECT_TABLE;
        } else {
            // get parent and child relation table
            $relation = CustomRelation::getRelationByParentChild($custom_table->id, $view_pivot_table_id);
            if (!$relation) {
                return null;
            }
            $searchType = (RelationType::ONE_TO_MANY == $relation->relation_type ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY);
        }

        return $relationTables->first(function ($relationTable) use ($view_pivot_column_id, $searchType) {
            // filtering $searchType
            $isMatchSearchType = false;
            if (isMatchString($relationTable->searchType, $searchType)) {
                $isMatchSearchType = true;
            }
            // if $searchType is SELECT_TABLE and SUMMARY_SELECT_TABLE
            elseif ($searchType == SearchType::SELECT_TABLE && SearchType::isSelectTable($relationTable->searchType)) {
                $isMatchSearchType = true;
            } elseif ($searchType == SearchType::ONE_TO_MANY && SearchType::isOneToMany($relationTable->searchType)) {
                $isMatchSearchType = true;
            } elseif ($searchType == SearchType::MANY_TO_MANY && SearchType::isManyToMany($relationTable->searchType)) {
                $isMatchSearchType = true;
            }
            if (!$isMatchSearchType) {
                return false;
            }

            // if select table, filtering selectTablePivotColumn
            if (isMatchString($searchType, SearchType::SELECT_TABLE) || isMatchString($searchType, SearchType::SUMMARY_SELECT_TABLE)) {
                $selectTablePivotColumn = $relationTable->selectTablePivotColumn;
                if (!$selectTablePivotColumn || !isMatchString($selectTablePivotColumn->id, $view_pivot_column_id)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get relation table by key (Ex. 27?view_pivot_column_id=parent_id&view_pivot_table_id=20)
     *
     * @param string|null $key. 27?view_pivot_column_id=parent_id&view_pivot_table_id=20
     * @return RelationTable|null
     */
    public static function getRelationTableByKey(?string $key): ?RelationTable
    {
        if (is_nullorempty($key) || strpos($key, '?') === false) {
            return null;
        }

        $custom_column_id = explode('?', $key)[0];
        parse_str(explode('?', $key)[1], $prms);

        return static::getRelationTable(
            $custom_column_id,
            array_get($prms, 'view_pivot_column_id'),
            array_get($prms, 'view_pivot_table_id')
        );
    }


    /**
     * Get custom tables as "select_table". They contains these columns matching them.
     *
     * @param CustomTable $custom_table target table
     * @param array $options execute options.
     * @return Collection
     */
    protected static function _getTablesSelectTable(CustomTable $custom_table, array $options): Collection
    {
        $results = collect();
        // 1. Get custom columns as "select_table". They contains these columns matching them.
        // * table_column > options > search_enabled is true.
        // * table_column > options > select_target_table is table id user selected.

        // get select tables custom columns.
        $custom_columns = CustomColumn::allRecords()
        ->filter(function ($custom_column) use ($custom_table, $options) {
            if (!ColumnType::isSelectTable($custom_column)) {
                return false;
            }
            $select_target_table = $custom_column->select_target_table;
            if (!isMatchString(array_get($select_target_table, 'id'), $custom_table->id)) {
                return false;
            }
            if (!$custom_column->index_enabled) {
                return false;
            }
            if ($options['search_enabled_only']) {
                $column_custom_table = $custom_column->custom_table_cache;
                if (!boolval($column_custom_table->getOption('search_enabled'))) {
                    return false;
                }
            }
            return true;
        });

        foreach ($custom_columns as $custom_column) {
            $table_obj = $custom_column->custom_table_cache;
            $results->push(new self([
                'searchType' => SearchType::SELECT_TABLE,
                'base_table' => $custom_table,
                'table' => $table_obj,
                'selectTablePivotColumn' => $custom_column,
            ]));
        }

        return $results;
    }


    /**
     * Get custom tables as "select_table" to parent.
     *
     * @param CustomTable $custom_table target table
     * @param array $options execute options.
     * @return Collection
     */
    protected static function _getParentTablesSelectTable(CustomTable $custom_table, array $options): Collection
    {
        $results = collect();

        // get select tables custom columns.
        $custom_columns = $custom_table->custom_columns_cache
            ->filter(function ($custom_column) use ($options) {
                if (!ColumnType::isSelectTable($custom_column)) {
                    return false;
                }
                if (!$custom_column->index_enabled) {
                    return false;
                }

                $select_target_table = $custom_column->select_target_table;
                if (!$select_target_table) {
                    return false;
                }

                if ($options['search_enabled_only'] && !boolval($select_target_table->getOption('search_enabled'))) {
                    return false;
                }
                return true;
            });

        foreach ($custom_columns as $custom_column) {
            $table_obj = $custom_column->select_target_table;

            $results->push(new self([
                'searchType' => SearchType::SUMMARY_SELECT_TABLE,
                'base_table' => $custom_table,
                'table' => $table_obj,
                'selectTablePivotColumn' => $custom_column,
            ]));
        }

        return $results;
    }

    /**
     * Get custom tables as "relation tables".
     *
     * @param CustomTable $custom_table
     * @param array $options
     * @return Collection
     */
    protected static function _getTablesRelation(CustomTable $custom_table, array $options): Collection
    {
        $results = collect();

        // 2. Get relation tables.
        // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
        $tables = CustomTable::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
        ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
            ->whereHas('custom_relations', function ($query) use ($custom_table) {
                $query->where('parent_custom_table_id', $custom_table->id);
            })->get(['child_custom_tables.*', 'custom_relations.id AS custom_relation_id', 'custom_relations.relation_type'])->toArray();
        foreach ($tables as $table) {
            $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
            $searchType = array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY;
            $results->push(new self([
                'searchType' => $searchType,
                'base_table' => $custom_table,
                'table' => $table_obj,
                'relation' => array_get($table, 'custom_relation_id'),
            ]));
        }

        return $results;
    }

    /**
     * Get custom tables as "relation tables" to parent.
     *
     * @param CustomTable $custom_table
     * @param array $options
     * @return Collection
     */
    protected static function _getParentTablesRelation(CustomTable $custom_table, array $options): Collection
    {
        $results = collect();

        // 2. Get relation tables.
        // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
        $tables = CustomTable::join('custom_relations', function ($join) use ($custom_table) {
            $join->on('custom_tables.id', '=', 'custom_relations.parent_custom_table_id')
                 ->where('custom_relations.child_custom_table_id', '=', $custom_table->id);
        })->join('custom_tables AS parent_custom_tables', 'parent_custom_tables.id', 'custom_relations.parent_custom_table_id')
            ->get(['parent_custom_tables.*', 'custom_relations.id AS custom_relation_id', 'custom_relations.relation_type'])->toArray();
        foreach ($tables as $table) {
            $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
            $searchType = array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::SUMMARY_ONE_TO_MANY : SearchType::SUMMARY_MANY_TO_MANY;
            $results->push(new self([
                'searchType' => $searchType,
                'base_table' => $custom_table,
                'table' => $table_obj,
                'relation' => array_get($table, 'custom_relation_id'),
            ]));
        }

        return $results;
    }


    /**
     * Set query as relation filter, all search type
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param mixed $searchType
     * @param mixed $value
     * @param array $params
     * @return mixed
     */
    public static function setQuery($query, $searchType, $value, $params = [])
    {
        $parent_table = CustomTable::getEloquent(array_get($params, 'parent_table'));
        $child_table = CustomTable::getEloquent(array_get($params, 'child_table'));

        switch ($searchType) {
            case SearchType::ONE_TO_MANY:
                return static::setQueryOneMany($query, $parent_table, $child_table, $value);
            case SearchType::MANY_TO_MANY:
                return static::setQueryManyMany($query, $parent_table, $child_table, $value);
            case SearchType::SELECT_TABLE:
                $custom_column = CustomColumn::getEloquent(array_get($params, 'custom_column'));
                if (\is_nullorempty($custom_column) && !\is_nullorempty($child_table)) {
                    $custom_column = $child_table->getSelectTableColumns($parent_table)->first();
                }
                return static::setQuerySelectTable($query, $custom_column, $value);
        }

        return $query;
    }

    /**
     * Set query as relation filter for 1:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param mixed $value
     * @return mixed
     */
    public static function setQueryOneMany($query, $parent_table, $child_table, $value)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        $dbName = getDBTableName($child_table);
        $query->whereOrIn("$dbName.parent_id", $value)->where("$dbName.parent_type", $parent_table->table_name);

        return $query;
    }


    /**
     * Set query as relation filter for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param CustomTable $child_table
     * @param mixed $value
     * @return mixed
     */
    public static function setQueryManyMany($query, $parent_table, $child_table, $value)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        if (is_nullorempty($relation)) {
            return;
        }

        $query->whereHas($relation->getRelationName(), function ($query) use ($relation, $value) {
            $query->whereOrIn($relation->getRelationName() . '.parent_id', $value);
        });

        return $query;
    }

    /**
     * Set query as relation filter for select table
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomColumn $custom_column select_table's column in $query's tbale
     * @param mixed $value
     * @return mixed
     */
    public static function setQuerySelectTable($query, $custom_column, $value)
    {
        if (is_nullorempty($custom_column)) {
            return;
        }

        $query->whereOrIn($custom_column->getQueryKey(), $value);

        return $query;
    }


    // join table ----------------------------------------------------

    /**
     * Set join to parent table, all search type
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param array $params
     * @return mixed
     */
    public function setParentJoin($query, $params = [])
    {
        $parent_table = CustomTable::getEloquent(array_get($params, 'parent_table'));
        $child_table = CustomTable::getEloquent(array_get($params, 'child_table'));
        $custom_column = CustomColumn::getEloquent(array_get($params, 'custom_column'));
        $leftJoin = boolval(array_get($params, 'leftJoin'));

        switch ($this->searchType) {
            case SearchType::ONE_TO_MANY:
                return $this->setParentJoinOneMany($query, $parent_table, $child_table, $leftJoin);
            case SearchType::MANY_TO_MANY:
                return $this->setParentJoinManyMany($query, $parent_table, $child_table, $leftJoin);
            case SearchType::SELECT_TABLE:
                if (\is_nullorempty($custom_column) && !\is_nullorempty($child_table)) {
                    $custom_column = $child_table->getSelectTableColumns($parent_table)->first();
                }
                return $this->setParentJoinSelectTable($query, $parent_table, $custom_column, $leftJoin);
        }

        return $query;
    }

    /**
     * Set join to child table, all search type
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param array $params
     * @return mixed
     */
    public function setSummaryChildJoin($query, $params = [])
    {
        $parent_table = CustomTable::getEloquent(array_get($params, 'parent_table'));
        $child_table = CustomTable::getEloquent(array_get($params, 'child_table'));
        $custom_column = CustomColumn::getEloquent(array_get($params, 'custom_column'));
        $leftJoin = boolval(array_get($params, 'leftJoin'));

        switch ($this->searchType) {
            case SearchType::SUMMARY_ONE_TO_MANY:
                return $this->setSummaryChildJoinOneMany($query, $parent_table, $child_table, $leftJoin);
            case SearchType::SUMMARY_MANY_TO_MANY:
                return $this->setSummaryChildJoinManyAndMany($query, $parent_table, $child_table, $leftJoin);
            case SearchType::SUMMARY_SELECT_TABLE:
                if (\is_nullorempty($custom_column) && !\is_nullorempty($parent_table)) {
                    $custom_column = $parent_table->getSelectTableColumns($child_table)->first();
                }
                return $this->setSummaryChildJoinSelectTable($query, $parent_table, $custom_column, $leftJoin);
        }

        return $query;
    }

    /**
     * Set parent join for 1:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public function setParentJoinOneMany($query, $parent_table, $child_table, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);

        // Append join query.
        $joinName = $leftJoin ? 'leftJoin' : 'join';
        $query->{$joinName}("$parent_table_name AS {$this->tableUniqueName}", "{$this->tableUniqueName}.id", "=", "$child_table_name.parent_id");

        return $query;
    }

    /**
     * Set parent join for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public function setParentJoinManyMany($query, $parent_table, $child_table, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        if (is_nullorempty($relation)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);
        $relation_name = $relation->getRelationName();

        // Append join query.
        $joinName = $leftJoin ? 'leftJoin' : 'join';
        $query->{$joinName}($relation_name, "$child_table_name.id", "=", "$relation_name.child_id")
            ->{$joinName}("$parent_table_name AS {$this->tableUniqueName}", function($join) use($relation_name) {
                $join->on("{$this->tableUniqueName}.id", "=", "$relation_name.parent_id")
                     ->whereNull("{$this->tableUniqueName}.deleted_at");
            });

        return $query;
    }


    /**
     * Set parent join for select table
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param CustomColumn $custom_column select_table's column in $query's table
     * @return mixed
     */
    public function setParentJoinSelectTable($query, $parent_table, $custom_column, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($custom_column)) {
            return;
        }

        // set unique table name joined target
        $custom_item = $custom_column->column_item;
        $custom_item->setUniqueTableName($this->tableUniqueName);

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $unique_table_name = $custom_item->sqlUniqueTableName();
        $child_table_name = getDBTableName($custom_column->custom_table_cache);
        $query_key = $custom_column->getQueryKey();

        // Append join query.
        $joinName = $leftJoin ? 'leftJoin' : 'join';
        $query->{$joinName}("$parent_table_name AS $unique_table_name", function ($join) use ($custom_item, $child_table_name, $unique_table_name, $query_key) {
            // If multiple, join as array string
            if ($custom_item->isMultipleEnabled()) {
                $join->whereInArrayColumn("$unique_table_name.id", "$child_table_name.$query_key");
            } else {
                $join->whereColumn("$unique_table_name.id", "=", "$child_table_name.$query_key");
            }
        })
        ;

        return $query;
    }


    /**
     * Set summary child join for 1:n relation. join sub query.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public function setSummaryChildJoinOneMany($query, $parent_table, $child_table, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);

        // Append join query.
        $joinName = $leftJoin ? 'leftJoinSub' : 'joinSub';
        $query->{$joinName}(function ($subQuery) use ($child_table_name) {
            // set from and default group by, select.
            $subQuery->from("$child_table_name AS {$this->tableUniqueName}")
                ->select("{$this->tableUniqueName}.parent_id")
                ->whereNull("{$this->tableUniqueName}.deleted_at")
                ->groupBy("{$this->tableUniqueName}.parent_id");

            // call subquery object callbacks.
            foreach ($this->subQueryCallbacks as $callback) {
                $callback($subQuery, $this);
            };
        }, $this->tableUniqueName, "{$this->tableUniqueName}.parent_id", "=", "$parent_table_name.id");

        return $query;
    }


    /**
     * Set child join for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public static function setChildJoinManyMany($query, $parent_table, $child_table, ?string $tableUniqueName = null)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        if (is_nullorempty($relation)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);
        $relation_name = $relation->getRelationName();

        // Append join query.
        $query->join($relation_name, "$parent_table_name.id", "=", "$relation_name.parent_id");

        if (isset($tableUniqueName)) {
            $query->join("$child_table_name AS $tableUniqueName", "$tableUniqueName.id", "=", "$relation_name.child_id");
        } else {
            $query->join($child_table_name, "$child_table_name.id", "=", "$relation_name.child_id");
        }

        return $query;
    }


    /**
     * Set child join for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public function setSummaryChildJoinManyAndMany($query, $parent_table, $child_table, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table, RelationType::MANY_TO_MANY);
        if (is_nullorempty($relation)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);
        $relation_name = $relation->getRelationName();


        // Append join query.
        $joinName = $leftJoin ? 'leftJoinSub' : 'joinSub';
        $query->{$joinName}(function ($subQuery) use ($child_table_name, $relation_name, $leftJoin) {
            $joinName = $leftJoin ? 'leftJoin' : 'join';

            // set from and default group by, select.
            $subQuery->from($relation_name)
                ->{$joinName}("$child_table_name AS {$this->tableUniqueName}", function($join) use($relation_name) {
                    $join->on("{$this->tableUniqueName}.id", "=", "$relation_name.child_id")
                         ->whereNull("{$this->tableUniqueName}.deleted_at");
                })
                ->select("{$relation_name}.parent_id")
                ->groupBy("{$relation_name}.parent_id");

            // call subquery object callbacks.
            foreach ($this->subQueryCallbacks as $callback) {
                $callback($subQuery, $this);
            };
        }, $this->tableUniqueName, "{$this->tableUniqueName}.parent_id", "=", "$parent_table_name.id");

        return $query;
    }

    /**
     * Set parent join for select table
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @param CustomColumn $custom_column select_table's column in $query's table
     * @return mixed
     */
    public function setSummaryChildJoinSelectTable($query, $parent_table, $custom_column, bool $leftJoin = false)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($custom_column)) {
            return;
        }

        // set unique table name joined target
        $custom_item = $custom_column->column_item;
        $custom_item->setUniqueTableName($this->tableUniqueName);

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($custom_column->custom_table_cache);
        $unique_table_name = $custom_column->column_item->sqlUniqueTableName();
        $query_key = $custom_column->getQueryKey();

        // Append join query.
        $joinName = $leftJoin ? 'leftJoinSub' : 'joinSub';
        $query->{$joinName}(function ($subQuery) use ($child_table_name, $query_key) {
            // set from and default group by, select.
            $subQuery->from("$child_table_name AS {$this->tableUniqueName}")
                ->select("{$this->tableUniqueName}.$query_key")
                ->whereNull("{$this->tableUniqueName}.deleted_at")
                ->groupBy("{$this->tableUniqueName}.$query_key");

            // call subquery object callbacks.
            foreach ($this->subQueryCallbacks as $callback) {
                $callback($subQuery, $this);
            };
        }, $this->tableUniqueName, function ($join) use ($custom_item, $parent_table_name, $unique_table_name, $query_key) {
            // If multiple, join as array string
            if ($custom_item->isMultipleEnabled()) {
                $join->whereInArrayColumn("$parent_table_name.id", "$unique_table_name.$query_key");
            } else {
                $join->whereColumn("$parent_table_name.id", "=", "$unique_table_name.$query_key");
            }
        });

        return $query;
    }



    /**
     * Create subquery for Workflow status join
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param CustomTable $custom_table
     * @param boolean $or_option
     * @return void
     */
    public static function setWorkflowStatusSubquery($query, CustomTable $custom_table, bool $or_option = false)
    {
        $tableName = getDBTableName($custom_table);
        $subquery = \DB::table($tableName)
            ->leftJoin(SystemTableName::WORKFLOW_VALUE, function ($join) use ($tableName, $custom_table) {
                $join->on(SystemTableName::WORKFLOW_VALUE . '.morph_id', "$tableName.id")
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                    ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', true);
            })->select(["$tableName.id as morph_id", 'morph_type', 'workflow_status_from_id', 'workflow_status_to_id']);

        // join query is $or_option is true then leftJoin
        $joinFunc = $or_option ? 'leftJoinSub' : 'joinSub';
        $query->{$joinFunc}($subquery, 'workflow_values', function ($join) use ($tableName) {
            $join->on($tableName . '.id', 'workflow_values.morph_id');
        });
    }


    /**
     * Create subquery for Workflow status join
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param CustomTable $custom_table
     * @param boolean $or_option
     * @return void
     */
    public static function setWorkflowWorkUsersSubQuery($query, $custom_table, $or_option = false)
    {
        $tableName = getDBTableName($custom_table);
        // get user table name
        $userTableName = getDBTableName(SystemTableName::USER);

        /////// first query. has workflow value's custom value
        $subquery = \DB::table($tableName)
            ->join(SystemTableName::VIEW_WORKFLOW_VALUE_UNION, function ($join) use ($tableName, $custom_table) {
                $join->on(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.custom_value_id', "$tableName.id")
                    ->where(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.custom_value_type', $custom_table->table_name)
                    ->where(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.workflow_table_id', $custom_table->id)
                ;
            })
            // join user table
            ->leftJoin($userTableName . ' AS last_executed_user', function ($join) {
                $join->on(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.last_executed_user_id', "last_executed_user.id")
                ;
            })
            ->leftJoin($userTableName . ' AS first_executed_user', function ($join) {
                $join->on(SystemTableName::VIEW_WORKFLOW_VALUE_UNION . '.first_executed_user_id', "first_executed_user.id")
                ;
            })
            ->leftJoin($userTableName . ' AS created_user', function ($join) use ($tableName) {
                $join->on($tableName . '.created_user_id', "created_user.id")
                ;
            })
            ///// add authority function for user or org
            ->where(function ($query) use ($tableName, $custom_table) {
                $classes = [
                    \Exceedone\Exment\ConditionItems\UserItem::class,
                    \Exceedone\Exment\ConditionItems\OrganizationItem::class,
                    \Exceedone\Exment\ConditionItems\ColumnItem::class,
                    \Exceedone\Exment\ConditionItems\SystemItem::class,
                    \Exceedone\Exment\ConditionItems\LoginUserColumnItem::class,
                ];

                foreach ($classes as $class) {
                    $class::setWorkflowConditionQuery($query, $tableName, $custom_table);
                }
            })
            ->distinct()
            ->select([$tableName .'.id  as morph_id']);


        /////// second query. not has workflow value's custom value
        $subquery2 = \DB::table($tableName)
            ->join(SystemTableName::VIEW_WORKFLOW_START, function ($join) use ($custom_table) {
                $join->where(SystemTableName::VIEW_WORKFLOW_START . '.workflow_table_id', $custom_table->id)
                ;
            })
            // filtering not contains workflow value
            ->whereNotExists(function ($query) use ($tableName, $custom_table) {
                $query->select(\DB::raw(1))
                    ->from(SystemTableName::WORKFLOW_VALUE)
                    ->whereColumn(SystemTableName::WORKFLOW_VALUE . '.morph_id', "$tableName.id")
                    ->where(SystemTableName::WORKFLOW_VALUE . '.morph_type', $custom_table->table_name)
                    ->where(SystemTableName::WORKFLOW_VALUE . '.latest_flg', 1)
                ;
            })
            ///// add authority function for user or org
            ->where(function ($query) use ($tableName, $custom_table) {
                $classes = [
                    \Exceedone\Exment\ConditionItems\UserItem::class,
                    \Exceedone\Exment\ConditionItems\OrganizationItem::class,
                    \Exceedone\Exment\ConditionItems\ColumnItem::class,
                    \Exceedone\Exment\ConditionItems\SystemItem::class,
                ];

                foreach ($classes as $class) {
                    $class::setWorkflowConditionQuery($query, $tableName, $custom_table);
                }
            })
            ->union($subquery)
            ->distinct()
            ->select([$tableName .'.id as morph_id']);

        // join query is $or_option is true then leftJoin
        $joinFunc = $or_option ? 'leftJoinSub' : 'joinSub';
        $query->{$joinFunc}($subquery2, 'workflow_values_wf', function ($join) use ($tableName) {
            $join->on($tableName . '.id', 'workflow_values_wf.morph_id');
        });
    }
}

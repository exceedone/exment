<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ColumnType;

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
     * Target table(Child table).
     *
     * @var CustomTable
     */
    public $table;

    /**
     * Search type
     *
     * @var SearchType
     */
    public $searchType;

    /**
     * Select table's pivot column
     *
     * @var CustomColumn
     */
    public $selectTablePivotColumn;

    /**
     * table's unique name. If join, set database table and use this table name.
     *
     * @var string
     */
    public $tableUniqueName;
    


    public function __construct(array $params = [])
    {
        $this->table = array_get($params, 'table');
        $this->searchType = array_get($params, 'searchType');
        $this->selectTablePivotColumn = array_get($params, 'selectTablePivotColumn');

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
            ],
            $options
        );

        // check already execute
        $key = sprintf(Define::SYSTEM_KEY_SESSION_TABLE_RELATION_TABLES, $custom_table->table_name);
        return System::requestSession($key, function () use ($custom_table, $options) {
            $results = [];
            // 1. Get custom columns as "select_table". They contains these columns matching them.
            // * table_column > options > search_enabled is true.
            // * table_column > options > select_target_table is table id user selected.
            
            // get select tables custom columns.
            $custom_columns = CustomColumn::allRecords()
                ->filter(function($custom_column) use($custom_table, $options){
                    if(!ColumnType::isSelectTable($custom_column)){
                        return false;
                    }
                    $select_target_table = $custom_column->select_target_table;
                    if(!isMatchString(array_get($select_target_table, 'id'), $custom_table->id)){
                        return false;
                    }
                    if(!$custom_column->index_enabled){
                        return false;
                    }
                    if($options['search_enabled_only']){
                        $column_custom_table = $custom_column->custom_table_cache;
                        if(!boolval($column_custom_table->getOption('search_enabled'))){
                            return false;
                        }
                    }
                    return true;
                });

            foreach ($custom_columns as $custom_column) {
                $table_obj = $custom_column->custom_table_cache;
                $results[] = new self([
                    'searchType' => SearchType::SELECT_TABLE, 
                    'table' => $table_obj,
                    'selectTablePivotColumn' => $custom_column,
                ]);
            }

            // 2. Get relation tables.
            // * table "custom_relations" and column "parent_custom_table_id" is $this->id.
            $tables = CustomTable::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
            ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
                ->whereHas('custom_relations', function ($query) use ($custom_table) {
                    $query->where('parent_custom_table_id', $custom_table->id);
                })->get(['child_custom_tables.*', 'custom_relations.relation_type'])->toArray();
            foreach ($tables as $table) {
                $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
                $searchType = array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY;
                $results[] = new self(['searchType' => $searchType, 'table' => $table_obj]);
            }

            return collect($results);
        })->filter(function ($result) use ($checkPermission) {
            // if not role, continue
            if ($checkPermission && !$result->table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                return false;
            }

            return true;
        });
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
                return static::setQueryOneMany($query, array_get($params, 'parent_table'), $value);
            case SearchType::MANY_TO_MANY:
                return static::setQueryManyMany($query, array_get($params, 'parent_table'), array_get($params, 'child_table'), $value);
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
    public static function setQueryOneMany($query, $parent_table, $value)
    {
        if (is_nullorempty($parent_table)) {
            return;
        }
        
        $query->whereOrIn("parent_id", $value)->where('parent_type', $parent_table->table_name);
        
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
        
        switch ($this->searchType) {
            case SearchType::ONE_TO_MANY:
                return $this->setParentJoinOneMany($query, $parent_table, $child_table);
            case SearchType::MANY_TO_MANY:
                return $this->setParentJoinManyMany($query, $parent_table, $child_table);
            case SearchType::SELECT_TABLE:
                if (\is_nullorempty($custom_column) && !\is_nullorempty($child_table)) {
                    $custom_column = $child_table->getSelectTableColumns($parent_table)->first();
                }
                return $this->setParentJoinSelectTable($query, $parent_table, $custom_column);
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
    public function setParentJoinOneMany($query, $parent_table, $child_table)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($child_table)) {
            return;
        }

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $child_table_name = getDBTableName($child_table);

        // Append join query.
        $query->join("$parent_table_name AS {$this->tableUniqueName}", "{$this->tableUniqueName}.id", "=", "$child_table_name.parent_id");
        
        return $query;
    }
    
    /**
     * Set parent join for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public function setParentJoinManyMany($query, $parent_table, $child_table)
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
        $query->join($relation_name, "$child_table_name.id", "=", "$relation_name.child_id")
            ->join("$parent_table_name AS {$this->tableUniqueName}", "{$this->tableUniqueName}.id", "=", "$relation_name.parent_id");
        
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
    public function setParentJoinSelectTable($query, $parent_table, $custom_column)
    {
        if (is_nullorempty($parent_table) || is_nullorempty($custom_column)) {
            return;
        }

        // set unique table name joined target
        $custom_column->column_item->setUniqueTableName($this->tableUniqueName);

        // Get DB table name
        $parent_table_name = getDBTableName($parent_table);
        $unique_table_name = $custom_column->column_item->sqlUniqueTableName();
        $child_table_name = getDBTableName($custom_column->custom_table_cache);
        $query_key = $custom_column->getQueryKey();

        // Append join query.
        $query->leftJoin("$parent_table_name AS $unique_table_name", "$unique_table_name.id", "=", "$child_table_name.$query_key")
            ;
        return $query;
    }
    
    /**
     * Set child join for n:n relation
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomTable $parent_table
     * @return mixed
     */
    public static function setChildJoinManyMany($query, $parent_table, $child_table)
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
        $query->join($relation_name, "$parent_table_name.id", "=", "$relation_name.parent_id")
            ->join($child_table_name, "$child_table_name.id", "=", "$relation_name.child_id")
            ;
        
        return $query;
    }
    
}

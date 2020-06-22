<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SearchType;

/**
 * Linkage item for Select table in form
 */
class Linkage
{
    public $parent_column;
    public $child_column;
    public $searchType;

    public function __construct(array $params = [])
    {
        $this->parent_column = array_get($params, 'parent_column');
        $this->child_column = array_get($params, 'child_column');
        $this->searchType = array_get($params, 'searchType');
    }

    public function parent_custom_table()
    {
        return isset($this->parent_column) ? $this->parent_column->custom_table_cache : null;
    }
    public function child_custom_table()
    {
        return isset($this->child_column) ? $this->child_column->custom_table_cache : null;
    }

    
    /**
     * get Select table's relation columns.
     * If there are two or more select_tables in the same table and they are in a parent-child relationship, parent-child relationship information is acquired.
     *
     * @return array contains parent_column, child_column, searchType
     */
    public static function getSelectTableLinkages($custom_table, $checkPermission = true)
    {
        $result = [];

        $columns = $custom_table->getSelectTableColumns();
        
        // re-loop for relation
        foreach ($columns as $column) {
            // get custom table
            $select_target_table = $column->select_target_table;
            if (!isset($select_target_table)) {
                continue;
            }

            // if same table, continue
            if ($custom_table->id == $select_target_table->id) {
                continue;
            }

            // get children tables
            $relations = $select_target_table->getRelationTables($checkPermission, ['search_enabled_only' => false]);
            // if not exists, continue
            if (!$relations) {
                continue;
            }
            foreach ($relations as $relation) {
                $child_custom_table = $relation->table;
                collect($columns)->filter(function ($child_column) use ($child_custom_table) {
                    return $child_column->select_target_table && $child_column->select_target_table->id == $child_custom_table->id;
                })
                ->each(function ($child_column) use ($column, $relation, &$result) {
                    $result[] = [
                        'parent_column' => $column,
                        'child_column' => $child_column,
                        'searchType' => $relation->searchType,
                    ];
                });
            }
        }

        return $result;
    }


    /**
     * Get Linkage list filter
     *
     * @param CustomColumn|string|null $parent_custom_column
     * @param CustomColumn|string|null $child_custom_column
     * @return \Illuminate\Support\Collection
     */
    public static function getLinkages($parent_custom_column, $child_custom_column)
    {
        $parent_custom_column = CustomColumn::getEloquent($parent_custom_column);
        $child_custom_column = CustomColumn::getEloquent($child_custom_column);

        if (!isset($child_custom_column)) {
            return collect();
        }

        return collect(static::getSelectTableLinkages($child_custom_column->custom_table_cache))
            ->filter(function ($relationColumn) use ($parent_custom_column, $child_custom_column) {
                if (isset($parent_custom_column)) {
                    if ($parent_custom_column->id != array_get($relationColumn, 'parent_column')->id) {
                        return false;
                    }
                }
                return array_get($relationColumn, 'child_column')->id == $child_custom_column->id;
            })->map(function ($relationColumn) {
                return new self($relationColumn);
            });
    }

    /**
     * Get Linkage
     *
     * @param CustomColumn|string|null $parent_custom_column
     * @param CustomColumn|string|null $child_custom_column
     * @return ?Linkage
     */
    public static function getLinkage($parent_custom_column, $child_custom_column)
    {
        return static::getLinkages($parent_custom_column, $child_custom_column)->first();
    }


    public function getParentValueId($custom_value)
    {
        if (!isset($custom_value)) {
            return null;
        }

        $custom_value = $this->parent_custom_table()->getValueModel($custom_value);

        return array_get($custom_value, 'value.' . $this->parent_column->column_name);
    }


    /**
     * Set relation filter to query.
     *
     * @param [type] $query target query.
     * @param CustomTable $custom_table
     * @param int|string|null $custom_value_id
     * @return void
     */
    public function setQueryFilter($query, $parent_v)
    {
        $parent_target_table = $this->parent_column->select_target_table;
        $child_target_table = $this->child_column->select_target_table;

        if ($this->searchType == SearchType::ONE_TO_MANY) {
            RelationTable::setQueryOneMany($query, $parent_target_table, $parent_v);
        }

        // n:n filter
        elseif ($this->searchType == SearchType::MANY_TO_MANY) {
            RelationTable::setQueryManyMany($query, $parent_target_table, $child_target_table, $parent_v);
        }
        // select_table filter
        else {
            if ($parent_target_table->id != $child_target_table->id) {
                $searchColumn = $child_target_table->getSelectTableColumns($parent_target_table->id)
                    ->first();
                RelationTable::setQuerySelectTable($query, $searchColumn, $parent_v);
            }
        }
    }
}

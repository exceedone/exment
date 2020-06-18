<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ColumnType;

/**
 * Relation column for search, relation filter(form), etc...
 */
class RelationColumn
{
    public $parent_column;
    public $child_column;
    public $searchType;

    public function __construct(array $params = []){
        $this->parent_column = array_get($params, 'parent_column');
        $this->child_column = array_get($params, 'child_column');
        $this->searchType = array_get($params, 'searchType');
    }

    public function parent_custom_table(){
        return isset($this->parent_column) ? $this->parent_column->custom_table_cache : null;
    }
    public function child_custom_table(){
        return isset($this->child_column) ? $this->child_column->custom_table_cache : null;
    }

    /**
     * Get RelationColumn list filter
     *
     * @param [type] $parent_column_id
     * @param [type] $child_custom_column
     * @return void
     */
    public static function getRelationColumns($parent_custom_column, $child_custom_column){
        $parent_custom_column = CustomColumn::getEloquent($parent_custom_column);
        $child_custom_column = CustomColumn::getEloquent($child_custom_column);

        if(!isset($child_custom_column)){
            return collect();
        }

        return collect($child_custom_column->custom_table_cache->getSelectTableRelationColumns())
            ->filter(function ($relationColumn) use($parent_custom_column, $child_custom_column) {
                if(isset($parent_custom_column)){
                    if($parent_custom_column->id != array_get($relationColumn, 'parent_column')->id){
                        return false;
                    }
                }
                return array_get($relationColumn, 'child_column')->id == $child_custom_column->id;
            })->map(function($relationColumn){
                return new self($relationColumn);
            });
    }

    /**
     * Get RelationColumn
     *
     * @param [type] $custom_table
     * @param [type] $parent_column_id
     * @param [type] $child_custom_column
     * @return void
     */
    public static function getRelationColumn($parent_custom_column, $child_custom_column){
        return static::getRelationColumns($parent_custom_column, $child_custom_column)->first();
    }


    public function getParentValueId($custom_value){
        if(!isset($custom_value)){
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
        $parent_target_table_id = $this->parent_column->select_target_table->id;
        $parent_target_table_name = $this->parent_column->select_target_table->table_name;

        // if value is array, execute query as 'whereIn'
        $whereFunc = is_list($parent_v) ? 'whereIn' : 'where';

        if ($this->searchType == SearchType::ONE_TO_MANY) {
            $query->{$whereFunc}("parent_id", $parent_v)->where('parent_type', $parent_target_table_name);
        }

        // n:n filter
        elseif ($this->searchType == SearchType::MANY_TO_MANY) {
            $relation = CustomRelation::getRelationByParentChild($this->parent_column->select_target_table, $this->child_column->select_target_table);
                $query->whereHas($relation->getRelationName(), function ($query) use($relation, $parent_v, $whereFunc) {
                    $query->{$whereFunc}($relation->getRelationName() . '.parent_id', $parent_v);
                });
        }
        // select_table filter
        else {
            $child_target_table_id = $this->child_column->select_target_table->id;
            if ($parent_target_table_id != $child_target_table_id) {
                $searchColumn = $this->child_column->select_target_table->custom_columns()
                    ->where('column_type', ColumnType::SELECT_TABLE)
                    ->whereIn('options->select_target_table', [strval($parent_target_table_id), intval($parent_target_table_id)])
                    ->first();
                if (isset($searchColumn)) {
                    $query->{$whereFunc}($searchColumn->getQueryKey(), $parent_v);
                }
            }
        }
    }
}

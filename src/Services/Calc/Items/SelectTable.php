<?php
namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;

/**
 * Calc service. column calc, js, etc...
 */
class SelectTable extends ItemBase
{
    /**
     * @var CustomColumn
     */
    public $select_target_column;
    
    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomColumn $select_target_column){
        parent::__construct($custom_column, $custom_table);
        $this->select_target_column = $select_target_column;
    }
    
    public function type(){
        return 'select_table';
    }

    public function text(){
        return array_get($this->custom_column, 'column_view_name') . '/' . array_get($this->select_target_column, 'column_view_name');
    }

    public function val(){
        return '${select_table:' . array_get($this->select_target_column, 'column_name') . '.' . array_get($this->custom_column, 'column_name') . '}';
    }

    public function toArray(){
        return array_merge([
            'pivot_column' => $this->select_target_column ? $this->select_target_column->column_name : null,
        ], parent::toArray());
    }


    public static function getItem($custom_column, $custom_table, ?CustomColumn $select_target_column){
        return new self($custom_column, $custom_table, $select_target_column);
    }


    public static function getItemBySplits($splits, ?CustomTable $custom_table){
        if(count($splits) < 2){
            return [];
        }
        $pivot_custom_column = CustomColumn::getEloquent($splits[0], $custom_table);
        $custom_column = CustomColumn::getEloquent($splits[1], $pivot_custom_column ? $pivot_custom_column->custom_table_id : null);
        return new self($custom_column, $custom_table, $pivot_custom_column);
    }


    public static function setCalcCustomColumnOptions($options, $id, $custom_table){
        $custom_table->custom_columns_cache->each(function ($column) use ($id, $options) {
            if (isset($id) && $id == array_get($column, 'id')) {
                return;
            }
            if (!ColumnType::isSelectTable(array_get($column, 'column_type'))) {
                return;
            }

            // get select table's calc column
            $column->select_target_table->custom_columns_cache->filter(function ($select_target_column) use ($id) {
                if (isset($id) && $id == array_get($select_target_column, 'id')) {
                    return false;
                }
                if (!ColumnType::isCalc(array_get($select_target_column, 'column_type'))) {
                    return false;
                }
    
                return true;
            })->each(function ($select_target_column) use ($column, $options) {
                $options->push(static::getItem($column, $custom_table, $select_target_column));
            });
        });
    }
}

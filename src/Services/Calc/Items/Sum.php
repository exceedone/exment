<?php
namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ColumnType;

/**
 * Calc service. column calc, js, etc...
 */
class Sum extends ItemBase
{
    /**
     * @var CustomTable
     */
    public $child_custom_table;
    
    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomTable $child_custom_table){
        parent::__construct($custom_column, $custom_table);
        $this->child_custom_table = $child_custom_table;
    }
    
    public function type(){
        return 'summary';
    }

    public function text(){
        return exmtrans('custom_column.child_sum_text', array_get($this->child_custom_table, 'table_view_name'), array_get($this->custom_column, 'column_view_name'));
    }

    public function val(){
        return '${sum:' . array_get($this->child_custom_table, 'table_name') . '.' . array_get($this->custom_column, 'column_name') . '}';
    }

    public static function getItem(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomTable $child_custom_table){
        return new self($custom_column, $custom_table, $child_custom_table);
    }

    public static function getItemBySplits($splits, ?CustomTable $custom_table){
        if(count($splits) < 2){
            return null;
        }
        $child_table = CustomTable::getEloquent($splits[0]);
        $custom_column = CustomColumn::getEloquent($splits[1], $custom_table);
        return new self($custom_column, $custom_table, $child_table);
    }

    public function toArray(){
        $child_relation_name = CustomRelation::getRelationNameByTables($this->custom_table, $this->child_custom_table);

        return array_merge([
            'child_table' => $this->child_custom_table,
            'child_relation_name' => $child_relation_name,
        ], parent::toArray());
    }

    public static function setCalcCustomColumnOptions($options, $id, $custom_table){
        // add child columns
        $child_relations = $custom_table->custom_relations;
        if (!isset($child_relations)) {
            return;
        }
        foreach ($child_relations as $child_relation) {
            $child_columns = $child_table->custom_columns_cache->filter(function ($column, $child_table) {
                return in_array(array_get($column, 'column_type'), ColumnType::COLUMN_TYPE_CALC());
            })->map(function ($column) use ($child_table, $child_table_name) {
                return static::getItem($column, $custom_table, $child_relation);
            })->each(function($column) use($options){
                $options->push($column);
            });
       }
   }
}

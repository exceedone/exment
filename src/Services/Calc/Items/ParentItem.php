<?php

namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\FormBlockType;

/**
 * Calc service. column calc, js, etc...
 */
class ParentItem extends ItemBase
{
    /**
     * @var CustomTable
     */
    public $parent_table;

    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomTable $parent_table)
    {
        parent::__construct($custom_column, $custom_table);
        $this->parent_table = $parent_table;
    }

    public function type()
    {
        return 'parent';
    }

    public function text()
    {
        return exmtrans('custom_column.calc_text.parent', array_get($this->custom_column, 'column_view_name'));
    }

    public function val()
    {
        return '${parent:' . array_get($this->custom_column, 'column_name') . '}';
    }

    public static function getItem(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomTable $parent_table)
    {
        return new self($custom_column, $custom_table, $parent_table);
    }

    public static function getItemBySplits($splits, ?CustomTable $custom_table)
    {
        $relation = CustomRelation::getRelationByChild($custom_table, RelationType::ONE_TO_MANY);
        if (!$relation) {
            return null;
        }
        $parent_table = $relation->parent_custom_table;
        $custom_column = CustomColumn::getEloquent($splits[0], $parent_table);
        $item = new self($custom_column, $custom_table, $parent_table);

        return $item;
    }


    public function toArray()
    {
        $array = [];
        if ($this->custom_form_block && $this->custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
            $array['child_relation_name'] = $this->getRelationName();
        }
        return array_merge($array, parent::toArray());
    }

    /**
     * Get triggered event key names
     *
     * @return array
     */
    public function getTriggeredKeys(): array
    {
        if ($this->custom_form_block) {
            // if has block, and form_block_type is default, this block is child table only form.
            // (Ex. "parent" and "child" table, and this form is only "child" form).
            if ($this->custom_form_block->form_block_type == FormBlockType::DEFAULT) {
                return [
                    'trigger_block' => 'parent_id',
                    'trigger_column' => 'parent_id',
                ];
            }
            // if has block, and form_block_type is not default, Check custom column and custom table.
            // (Ex. "parent" and "child" table, and this form is "parent" form and contains child).
            else {
                // If same column's table id and custom table's id, this form is child
                if (isMatchString($this->custom_column->custom_table_id, $this->custom_table->id)) {
                    return [
                        'trigger_block' => $this->getRelationName(),
                        'trigger_column' => array_get($this->custom_column, 'column_name'),
                    ];
                }
                // else not match is, block is default.
                return [
                    'trigger_block' => 'default',
                    'trigger_column' => array_get($this->custom_column, 'column_name'),
                ];
            }
        }

        return [
            'trigger_block' => 'default',
            'trigger_column' => array_get($this->custom_column, 'column_name'),
        ];
    }

    public static function setCalcCustomColumnOptions($options, $id, $custom_table)
    {
        // get parent table only 1:n
        $relation = CustomRelation::getRelationByChild($custom_table, RelationType::ONE_TO_MANY);
        if (!$relation) {
            return;
        }

        $parent_table = $relation->parent_custom_table;
        $parent_table->custom_columns_cache->filter(function ($custom_column) use ($id) {
            if (isset($id) && $id == array_get($custom_column, 'id')) {
                return false;
            }
            if (!ColumnType::isCalc(array_get($custom_column, 'column_type'))) {
                return false;
            }
            return true;
        })->each(function ($custom_column) use ($parent_table, $custom_table, $options) {
            $options->push(static::getItem($custom_column, $custom_table, $parent_table));
        });
    }

    protected function getRelationName()
    {
        return CustomRelation::getRelationNameByTables($this->parent_table, $this->custom_table);
    }
}

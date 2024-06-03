<?php

namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;

/**
 * Calc service. column calc, js, etc...
 */
class SelectTable extends ItemBase
{
    /**
     * select pivot column.
     * This column is in this custom table.
     *
     * @var CustomColumn|null
     */
    public $select_pivot_column;

    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table, ?CustomColumn $select_pivot_column)
    {
        parent::__construct($custom_column, $custom_table);
        $this->select_pivot_column = $select_pivot_column;
    }

    public function type()
    {
        return 'select_table';
    }

    public function text()
    {
        return exmtrans('custom_column.calc_text.select_table', array_get($this->select_pivot_column, 'column_view_name'), array_get($this->custom_column, 'column_view_name'));
    }

    public function val()
    {
        return '${select_table:' . array_get($this->select_pivot_column, 'column_name') . '.' . array_get($this->custom_column, 'column_name') . '}';
    }

    public function toArray()
    {
        return array_merge([
            'select_pivot_column' => $this->select_pivot_column ? $this->select_pivot_column->column_name : null,
        ], parent::toArray());
    }

    /**
     * Get triggered event key names
     *
     * @return array
     */
    public function getTriggeredKeys(): array
    {
        $trigger_block = (!$this->custom_form_block || $this->custom_form_block->form_block_type == FormBlockType::DEFAULT) ? 'default' : $this->getRelationName();
        return [
            'trigger_block' => $trigger_block,
            'trigger_column' => $this->select_pivot_column ? $this->select_pivot_column->column_name : null,
        ];
    }


    public static function getItem($custom_column, $custom_table, ?CustomColumn $select_pivot_column)
    {
        return new self($custom_column, $custom_table, $select_pivot_column);
    }


    public static function getItemBySplits($splits, ?CustomTable $custom_table)
    {
        if (count($splits) < 2) {
            return [];
        }
        $pivot_custom_column = CustomColumn::getEloquent($splits[0], $custom_table);
        $custom_column = CustomColumn::getEloquent($splits[1], $pivot_custom_column ? $pivot_custom_column->select_target_table : null);
        return new self($custom_column, $custom_table, $pivot_custom_column);
    }


    public static function setCalcCustomColumnOptions($options, $id, $custom_table)
    {
        $custom_table->custom_columns_cache->each(function ($custom_column) use ($id, $custom_table, $options) {
            if (isset($id) && $id == array_get($custom_column, 'id')) {
                return;
            }
            if (!ColumnType::isSelectTable(array_get($custom_column, 'column_type'))) {
                return;
            }

            // get select table's calc column
            $custom_column->select_target_table->custom_columns_cache->filter(function ($select_pivot_column) use ($id) {
                if (isset($id) && $id == array_get($select_pivot_column, 'id')) {
                    return false;
                }
                if (!ColumnType::isCalc(array_get($select_pivot_column, 'column_type'))) {
                    return false;
                }

                return true;
            })->each(function ($select_target_column) use ($custom_column, $custom_table, $options) {
                $options->push(static::getItem($select_target_column, $custom_table, $custom_column));
            });
        });
    }
}

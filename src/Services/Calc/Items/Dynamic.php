<?php

namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;

/**
 * Calc service. column calc, js, etc...
 */
class Dynamic extends ItemBase
{
    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table)
    {
        parent::__construct($custom_column, $custom_table);
    }

    public function type()
    {
        return 'dynamic';
    }

    public function text()
    {
        return array_get($this->custom_column, 'column_view_name');
    }

    public function val()
    {
        return '${value:' . array_get($this->custom_column, 'column_name') . '}';
    }

    public static function getItem(?CustomColumn $custom_column, ?CustomTable $custom_table)
    {
        return new self($custom_column, $custom_table);
    }

    public static function getItemBySplits($splits, ?CustomTable $custom_table)
    {
        $custom_column = CustomColumn::getEloquent($splits[0], $custom_table);
        return new self($custom_column, $custom_table);
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
            'trigger_column' => array_get($this->custom_column, 'column_name'),
        ];
    }


    public static function setCalcCustomColumnOptions($options, $id, $custom_table)
    {
        // get calc options
        $custom_table->custom_columns_cache->filter(function ($column) use ($id) {
            if (isset($id) && $id == array_get($column, 'id')) {
                return false;
            }
            if (!ColumnType::isCalc(array_get($column, 'column_type'))) {
                return false;
            }

            return true;
        })->each(function ($column) use ($custom_table, &$options) {
            $options->push(static::getItem($column, $custom_table));
        });
    }
}

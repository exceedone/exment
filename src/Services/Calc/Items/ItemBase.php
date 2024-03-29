<?php

namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormBlock;

/**
 * Calc service. column calc, js, etc...
 */
abstract class ItemBase implements CalcInterface
{
    /**
     * $target column. This column is selected by "calcModal", and getting value's column.
     * *For select table is select target table's column.
     * *For parent is parent table's column.
     * *For summary is child table's column.
     *
     * @var CustomColumn|null
     */
    protected $custom_column;

    /**
     * $target table. *Maybe not match $custom_column->custom_table*
     *
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * @var CustomFormBlock|false|null
     */
    public $custom_form_block;

    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table)
    {
        $this->custom_column = $custom_column;
        $this->custom_table = $custom_table;
    }

    public function displayText()
    {
        $text = $this->text();
        return '${' . $text . '}';
    }

    /**
     * Get triggered event key names
     *
     * @return array
     */
    public function getTriggeredKeys(): array
    {
        return [
            'trigger_block' => 'default',
            'trigger_column' => $this->custom_column ? $this->custom_column->column_name : null,
        ];
    }

    public function toArray()
    {
        return array_merge([
            'custom_column' => $this->custom_column,
            'formula_column' => $this->custom_column ? $this->custom_column->column_name : null,
            'val' => $this->val(),
            'type' => $this->type(),
            'displayText' => $this->displayText(),
        ], $this->getTriggeredKeys());
    }


    public function setCustomFormBlock($custom_form_block)
    {
        $this->custom_form_block = $custom_form_block;
        return $this;
    }

    public function getCustomFormBlock()
    {
        return $this->custom_form_block;
    }

    protected function getRelationName()
    {
        return $this->custom_form_block ? $this->custom_form_block->getRelationInfo()[1] : null;
    }
}

<?php

namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;

/**
 * Calc service. column calc, js, etc...
 */
class Count extends ItemBase
{
    /**
     * @var CustomTable
     */
    public $child_custom_table;

    public function __construct(?CustomTable $custom_table, ?CustomTable $child_custom_table)
    {
        parent::__construct(null, $custom_table);
        $this->child_custom_table = $child_custom_table;
    }

    public function type()
    {
        return 'count';
    }

    public function text()
    {
        return exmtrans('custom_column.calc_text.child_count', array_get($this->child_custom_table, 'table_view_name'));
    }

    public function val()
    {
        return '${count:' . array_get($this->child_custom_table, 'table_name') .'}';
    }

    public static function getItem(?CustomTable $custom_table, ?CustomTable $child_custom_table)
    {
        return new self($custom_table, $child_custom_table);
    }

    public static function getItemBySplits($splits, ?CustomTable $custom_table)
    {
        $child_table = CustomTable::getEloquent($splits[0]);
        return new self($custom_table, $child_table);
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
            'trigger_column' => null, // count is not called field
        ];
    }

    public function toArray()
    {
        $child_relation_name = CustomRelation::getRelationNameByTables($this->custom_table, $this->child_custom_table);

        return array_merge([
            'child_table' => $this->child_custom_table,
            'child_relation_name' => $child_relation_name,
        ], parent::toArray());
    }

    public static function setCalcCustomColumnOptions($options, $id, $custom_table)
    {
        // add child columns
        $child_relations = $custom_table->custom_relations;
        if (!isset($child_relations)) {
            return;
        }
        foreach ($child_relations as $child_relation) {
            $child_table = $child_relation->child_custom_table;
            $options->push(static::getItem($custom_table, $child_table));
        }
    }
}

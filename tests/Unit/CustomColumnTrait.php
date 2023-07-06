<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

trait CustomColumnTrait
{
    /**
     * Get CustomColumn model.
     * *We can create custom column model if not save database.
     *
     * @return CustomColumn
     */
    protected function getCustomColumnModel($column_type, $options = [])
    {
        $custom_column = new CustomColumn([
            'column_name' => $column_type,
            'column_view_name' => $column_type,
            'column_type' => $column_type,
            'options' => $options,
        ]);

        return $custom_column;
    }

    /**
     * Get Custom Value and column item.
     * *We can create custom value model if not save database.
     *
     * @return array
     */
    protected function getCustomValueAndColumnItem(&$custom_column, $value)
    {
        $classname = getModelName(CustomTable::getEloquent('information'));
        $custom_value = new $classname([
            'id' => 1, // dummy
            'value' => [
                $custom_column->column_name => $value,
            ],
        ]);

        $column_item = $custom_column->column_item->setCustomValue($custom_value);

        return [$custom_value, $column_item];
    }
}

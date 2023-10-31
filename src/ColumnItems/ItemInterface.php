<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Model\CustomForm;

/**
 * @method mixed getTableColumn()
 * @method mixed setOtherFormColumns(array $other_form_columns)
 * @method mixed setFormColumnOptions($form_column_options)
 * @method mixed setCustomForm(CustomForm $custom_form)
 * @method mixed isMultipleEnabled()
 * @method string getCastWrapTableColumn(?string $column_name = null)
 * @method mixed isDateTime()
 */
interface ItemInterface
{
    /**
     * get column key name
     */
    public function name();

    /**
     * get column key sql name.
     */
    public function sqlname();

    /**
     * get column index name
     */
    public function index();

    /**
     * get value. (only this column's value. not custom_value)
     */
    public function value();

    /**
     * get pure value.
     */
    public function pureValue();

    /**
     * get text(for display)
     */
    public function text();

    /**
     * get html
     */
    public function html();

    /**
     * get grid style
     */
    public function gridStyle();

    /**
     * get or set value's id.
     */
    public function id($id = null);

    /**
     * get or set header label.
     */
    public function label($label = null);

    /**
     * get or set option for convert
     */
    public function options($options = null);

    /**
     * sortable grid
     */
    public function sortable();

    /**
     * set custom value
     */
    public function setCustomValue($custom_value);

    /**
     * prepare value
     */
    public function prepare();

    /**
     * get custom table
     */
    public function getCustomTable();

    /**
     * get view filter type
     */
    public function getViewFilterType();

    /**
     * Convert filter value.
     * Ex. If value is decimal and Column Type is decimal, return floatval.
     *
     * @param mixed $value
     * @return mixed
     */
    public function convertFilterValue($value);

    /**
     * set admin filter for filtering grid.
     */
    public function setAdminFilter(&$filter);

    /**
     * get item model
     */
    public static function getItem(...$options);
}

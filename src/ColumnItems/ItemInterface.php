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
    // @phpstan-ignore-next-line
    public function name();

    /**
     * get column key sql name.
     */
    // @phpstan-ignore-next-line
    public function sqlname();

    /**
     * get column index name
     */
    // @phpstan-ignore-next-line
    public function index();

    /**
     * get value. (only this column's value. not custom_value)
     */
    // @phpstan-ignore-next-line
    public function value();

    /**
     * get pure value.
     */
    // @phpstan-ignore-next-line
    public function pureValue();

    /**
     * get text(for display)
     */
    // @phpstan-ignore-next-line
    public function text();

    /**
     * get html
     */
    // @phpstan-ignore-next-line
    public function html();

    /**
     * get grid style
     */
    // @phpstan-ignore-next-line
    public function gridStyle();

    /**
     * get or set value's id.
     */
    // @phpstan-ignore-next-line
    public function id($id = null);

    /**
     * get or set header label.
     */
    // @phpstan-ignore-next-line
    public function label($label = null);

    /**
     * get or set option for convert
     */
    // @phpstan-ignore-next-line
    public function options($options = null);

    /**
     * sortable grid
     */
    // @phpstan-ignore-next-line
    public function sortable();

    /**
     * set custom value
     */
    // @phpstan-ignore-next-line
    public function setCustomValue($custom_value);

    /**
     * prepare value
     */
    // @phpstan-ignore-next-line
    public function prepare();

    /**
     * get custom table
     */
    // @phpstan-ignore-next-line
    public function getCustomTable();

    /**
     * get view filter type
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function setAdminFilter(&$filter);

    /**
     * get item model
     */
    // @phpstan-ignore-next-line
    public static function getItem(...$options);
}

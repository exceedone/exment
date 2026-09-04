<?php

namespace Exceedone\Exment\Services;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Form helper
 */
class FormHelper
{
    /**
     * Get form field. be called by value form, importer.
     *
     * @param mixed $custom_table
     * @param mixed $column
     * @param mixed $custom_value_or_id
     * @param mixed $form_column
     * @param mixed $column_name_prefix
     * @param bool $validate
     * @return mixed
     * @deprecated Please use getFormFieldObj.
     */
    public static function getFormField($custom_table, $column, $custom_value_or_id = null, $form_column = null, $column_name_prefix = null, $validate = false)
    {
        $options = [];
        if ($validate) {
            $options['forValidate'] = true;
        }

        $column_item = isset($form_column) ? $form_column->column_item : $column->column_item;
        if ($custom_value_or_id instanceof CustomValue && method_exists($column_item, 'setCustomValue')) {
            // @phpstan-ignore-next-line
            $column_item->setCustomValue($custom_value_or_id);
        } else {
            $column_item->id($custom_value_or_id);
        }
        return $column_item->options($options)->getAdminField($form_column, $column_name_prefix);
    }


    /**
     * Get form field. be called by value form, importer.
     *
     * @param CustomTable $custom_table
     * @param CustomColumn $column
     * @param array<string, mixed> $options
     * @return mixed
     */
    public static function getFormFieldObj(CustomTable $custom_table, CustomColumn $column, array $options = [])
    {
        $options = array_merge([
            'custom_value_or_id' => null,
            'form_column' => null,
            'column_name_prefix' => null,
            'validate' => false,
            'columnOptions' => [], // column item's options
        ], $options);
        $custom_value_or_id = $options['custom_value_or_id'];
        $form_column = $options['form_column'];
        $column_name_prefix = $options['column_name_prefix'];
        $validate = $options['validate'];
        $columnOptions = $options['columnOptions'];

        if ($validate) {
            $columnOptions['forValidate'] = true;
        }

        $column_item = isset($form_column) ? $form_column->column_item : $column->column_item;
        if ($custom_value_or_id instanceof CustomValue && method_exists($column_item, 'setCustomValue')) {
            // @phpstan-ignore-next-line
            $column_item->setCustomValue($custom_value_or_id);
        } else {
            $column_item->id($custom_value_or_id);
        }
        return $column_item->options($columnOptions)->getAdminField($form_column, $column_name_prefix);
    }
}

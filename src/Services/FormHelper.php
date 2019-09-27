<?php
namespace Exceedone\Exment\Services;

use Encore\Admin\Form\Field;

/**
 * Form helper
 */
class FormHelper
{
    /**
     * Get form field. be called by value form, importer.
     */
    public static function getFormField($custom_table, $column, $id = null, $form_column = null, $column_name_prefix = null, $validate = false)
    {
        $options = [];
        if($validate){
            $options['forValidate'] = true;
        }

        $column_item = isset($form_column) ? $form_column->column_item : $column->column_item;
        return $column_item->id($id)->options($options)->getAdminField($form_column, $column_name_prefix);
    }
}

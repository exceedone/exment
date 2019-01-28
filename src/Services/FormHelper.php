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
    public static function getFormField($custom_table, $column, $id = null, $form_column = null, $column_name_prefix = null)
    {
        if(isset($form_column)){
            return $form_column->column_item->id($id)->getAdminField($form_column, $column_name_prefix);
        }
        return $column->column_item->id($id)->getAdminField(null, $column_name_prefix);
    }
}

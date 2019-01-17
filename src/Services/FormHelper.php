<?php
namespace Exceedone\Exment\Services;

use Illuminate\Support\Facades\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Validator;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Field as ExmentField;

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
            return $form_column->column_item->getAdminField($form_column, $column_name_prefix);
        }
        return $column->column_item->getAdminField(null, $column_name_prefix);
    }
}

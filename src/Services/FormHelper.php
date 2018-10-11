<?php
namespace Exceedone\Exment\Services;

use Illuminate\Support\Facades\File;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\MailTemplate;
use ZipArchive;
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
    public static function getFormField($custom_table, $column, $id = null, $form_column = null, $column_name_prefix = null){
        $form_column_options = $form_column->options ?? null;
        $options = $column->options;
        $column_name = $column->column_name;
        $column_view_name = $column->column_view_name;

        // form column name. join $column_name_prefix and $column_name
        $form_column_name = $column_name_prefix.$column_name;
    
        // if hidden setting, add hidden field and continue
        if(isset($form_column_options) && boolval(array_get($form_column_options, 'hidden'))){
            return new Field\Hidden($form_column_name);
        }

        switch ($column->column_type) {
            case 'text':
                $field = new Field\Text($form_column_name, [$column_view_name]);
                break;
            case 'textarea':
                $field = new Field\Textarea($form_column_name, [$column_view_name]);
                break;
            case 'url':
                $field = new Field\Url($form_column_name, [$column_view_name]);
                break;
            case 'email':
                $field = new Field\Email($form_column_name, [$column_view_name]);
                break;
            case 'password':
                $field = new Field\Password($form_column_name, [$column_view_name]);
                break;
            case 'integer':
                $field = new ExmentField\Number($form_column_name, [$column_view_name]);
                if (isset($options) && !is_null(array_get($options, 'number_min'))) {
                    $field->attribute(['min' => array_get($options, 'number_min')]);
                }
                if (isset($options) && !is_null(array_get($options, 'number_max'))) {
                    $field->attribute(['max' => array_get($options, 'number_max')]);
                }
                // if set updown button
                if(!boolval(array_get($options, 'updown_button'))){
                    $field->disableUpdown();
                }
                break;
            case 'decimal':
                $field = new Field\Text($form_column_name, [$column_view_name]);
                if (isset($options) && !is_null(array_get($options, 'number_min'))) {
                    $field->attribute(['min' => array_get($options, 'number_min')]);
                }
                if (isset($options) && !is_null(array_get($options, 'number_max'))) {
                    $field->attribute(['max' => array_get($options, 'number_max')]);
                }
                break;
            case 'date':
                $field = new Field\Date($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case 'time':
                $field = new Field\Time($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case 'datetime':
                $field = new Field\Datetime($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case 'select':
            case 'select_valtext':
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleSelect($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Select($form_column_name, [$column_view_name]);
                }
                // create select
                $field->options(createSelectOptions($column));
                break;
            case 'select_table':
            case 'user':
            case 'organization':
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleSelect($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Select($form_column_name, [$column_view_name]);
                }

                // get select_target_table
                if ($column->column_type == 'select_table') {
                    $select_target_table_id = array_get($options, 'select_target_table');
                    if (isset($select_target_table_id)) {
                        $select_target_table = CustomTable::find($select_target_table_id)->table_name;
                    } else {
                        $select_target_table = null;
                    }
                } elseif ($column->column_type == Define::SYSTEM_TABLE_NAME_USER) {
                    $select_target_table = CustomTable::findByName(Define::SYSTEM_TABLE_NAME_USER)->table_name;
                } elseif ($column->column_type == Define::SYSTEM_TABLE_NAME_ORGANIZATION) {
                    $select_target_table = CustomTable::findByName(Define::SYSTEM_TABLE_NAME_ORGANIZATION)->table_name;
                }
                $field->options(function ($val) use ($select_target_table) {
                    // get DB option value
                    $select_options = getOptions($select_target_table, $val);
                    return $select_options;
                });
                // get ajax
                $ajax = getOptionAjaxUrl($select_target_table);
                if (isset($ajax)) {
                    $field->ajax($ajax);
                }
                break;
            case 'yesno':
                $field = new ExmentField\SwitchBoolField($form_column_name, [$column_view_name]);
                break;
            case 'boolean':
                $field = new Field\SwitchField($form_column_name, [$column_view_name]);
                // set options
                $states = [
                    'on'  => ['value' => array_get($options, 'true_value'), 'text' => array_get($options, 'true_label')],
                    'off' => ['value' => array_get($options, 'false_value'), 'text' => array_get($options, 'false_label')],
                ];
                $field->states($states);
                break;
            case 'auto_number':
                $field = new ExmentField\Display($form_column_name, [$column_view_name]);
                break;
            case 'image':
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleImage($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Image($form_column_name, [$column_view_name]);
                }
                // set file options
                $field->options(
                    static::getFileOptions($custom_table, $column, $id)
                )->removable();
                break;
            case 'file':
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleFile($form_column_name, [$column_view_name]);
                } else {
                    $field = new ExmentField\NestedFile($form_column_name, [$column_view_name]);
                }
                // set file options
                $field->options(
                    array_merge(
                        static::getFileOptions($custom_table, $column, $id)
                    //, ['showPreview' => false]
                    )
                )->removable();
                break;
            default:
                $field = new Field\Text($form_column_name, [$column_view_name]);
                break;
        }
        
        // setting options --------------------------------------------------
        // placeholder
        if (array_has_value($options, 'placeholder')) {
            $field->placeholder(array_get($options, 'placeholder'));
        }

        // number_format
        if (isset($options) && boolval(array_get($options, 'number_format'))) {
            $field->attribute(['number_format' => true]);
        }

        // readonly
        if (isset($form_column_options) && boolval(array_get($form_column_options, 'view_only'))) {
            $field->attribute(['readonly' => true]);
        }

        // set validates
        $validate_options = [];
        $validates = static::getColumnValidates($custom_table, $column, $id, $validate_options);
        // set validates
        if(count($validates)){
            $field->rules($validates);
        }
    
        // set help string using result_options
        $help = null;
        $help_regexes = array_get($validate_options, 'help_regexes');
        if (array_has_value($options, 'help')) {
            $help = array_get($options, 'help');
        }
        if(isset($help_regexes)){
            $help .= sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes));
        }
        if(isset($help)){
            $field->help($help);
        }

        return $field;
    }
    /**
     * Get column validate array.
     * @param string|CustomTable|array $table_obj table object
     * @param string column_name target column name
     * @param array result_options Ex help string, ....
     * @return string
     */
    public static function getColumnValidates($table_obj, $column_name, $value_id = null, &$result_options = [])
    {
        // get column and options
        $table_obj = CustomTable::getEloquent($table_obj);
        $custom_column = CustomColumn::getEloquent($column_name, $table_obj);
        $options = array_get($custom_column, 'options');
        $column_type = array_get($custom_column, 'column_type');
        if (!isset($options)) {
            return [];
        }
        
        $valudates = [];
        // setting options --------------------------------------------------
        // required
        if (boolval(array_get($options, 'required'))) {
            $valudates[] = 'required';
        } else {
            $valudates[] = 'nullable';
        }
    
        // unique
        if (boolval(array_get($options, 'unique'))) {
            // add unique field
            $unique_table_name = getDBTableName($table_obj); // database table name
            $unique_column_name = "value->".array_get($custom_column, 'column_name'); // column name
            // create rules.if isset id, add
            $rules = "unique:$unique_table_name,$unique_column_name" . (isset($value_id) ? ",$value_id" : "");
            // add rules
            $valudates[] = $rules;
        }

        // regex rules
        $help_regexes = [];
        if (array_has_value($options, 'available_characters')) {
            $available_characters = array_get($options, 'available_characters');
            $regexes = [];
            // add regexes using loop
            foreach ($available_characters as $available_character) {
                switch ($available_character) {
                    case 'lower':
                        $regexes[] = 'a-z';
                        $help_regexes[] = exmtrans('custom_column.available_characters.lower');
                        break;
                    case 'upper':
                        $regexes[] = 'A-Z';
                        $help_regexes[] = exmtrans('custom_column.available_characters.upper');
                        break;
                    case 'number':
                        $regexes[] = '0-9';
                        $help_regexes[] = exmtrans('custom_column.available_characters.number');
                        break;
                    case 'hyphen_underscore':
                        $regexes[] = '_\-';
                        $help_regexes[] = exmtrans('custom_column.available_characters.hyphen_underscore');
                        break;
                    case 'symbol':
                        $regexes[] = '!"#$%&\'()\*\+\-\.,\/:;<=>?@\[\]^_`{}~';
                        $help_regexes[] = exmtrans('custom_column.available_characters.symbol');
                    break;
                }
            }
            if (count($regexes) > 0) {
                $valudates[] = 'regex:/^['.implode("", $regexes).']*$/';
            }
        }
        
        // set help_regexes to result_options
        if (count($help_regexes) > 0) {
            $result_options['help_regexes'] = $help_regexes;
        }

        // text validate
        if (in_array($column_type, ['text','textarea'])) {
            // string_length
            if (array_get($options, 'string_length')) {
                $valudates[] = 'size:'.array_get($options, 'string_length');
            }
        }

        // number attribute
        if (in_array($column_type, ['integer','decimal'])) {
            // value size
            if (array_get($options, 'number_min')) {
                $valudates[] = 'min:'.array_get($options, 'number_min');
            }
            if (array_get($options, 'number_max')) {
                $valudates[] = 'max:'.array_get($options, 'number_max');
            }
        }

        return $valudates;
    }

    protected static function getFileOptions($custom_table, $custom_column, $id){
        return [
            'deleteUrl' => admin_url(url_join('data', $custom_table->table_name, $id, 'filedelete')),
            'deleteExtraData'      => [
                Field::FILE_DELETE_FLAG         => $custom_column->column_name,
                '_token'                         => csrf_token(),
                '_method'                        => 'PUT',
            ],
        ];
    }
}

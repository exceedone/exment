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
        $form_column_options = $form_column->options ?? null;
        $options = $column->options;
        $column_name = $column->column_name;
        $column_view_name = $column->column_view_name;

        // form column name. join $column_name_prefix and $column_name
        $form_column_name = $column_name_prefix.$column_name;
    
        // if hidden setting, add hidden field and continue
        if (boolval(array_get($form_column_options, 'hidden'))) {
            $field = new Field\Hidden($form_column_name);
        }
        // readonly
        // elseif (boolval(array_get($form_column_options, 'view_only'))) {
        //     $field = new ExmentField\Display($form_column_name, [$column_view_name]);
        //     $field->display(function($value){
        //         return $value;
        //     });
        // }
        else {
            switch ($column->column_type) {
            case ColumnType::TEXT:
                $field = new Field\Text($form_column_name, [$column_view_name]);
                break;
            case ColumnType::TEXTAREA:
                $field = new Field\Textarea($form_column_name, [$column_view_name]);
                $field->rows(array_get($options, 'rows', 6));
                break;
            case ColumnType::EDITOR:
                $field = new ExmentField\Tinymce($form_column_name, [$column_view_name]);
                $field->rows(array_get($options, 'rows', 6));
                break;
            case ColumnType::URL:
                $field = new Field\Url($form_column_name, [$column_view_name]);
                break;
            case ColumnType::EMAIL:
                $field = new Field\Email($form_column_name, [$column_view_name]);
                break;
            case ColumnType::INTEGER:
                $field = new ExmentField\Number($form_column_name, [$column_view_name]);
                // if set updown button
                if (!boolval(array_get($options, 'updown_button'))) {
                    $field->disableUpdown();
                    $field->defaultEmpty();
                }
                break;
            case ColumnType::DECIMAL:
                $field = new Field\Text($form_column_name, [$column_view_name]);
                break;
            case ColumnType::CURRENCY:
                $field = new Field\Text($form_column_name, [$column_view_name]);
                // get symbol
                $symbol = array_get($options, 'currency_symbol');
                $field->prepend($symbol);
                $field->attribute(['style' => 'max-width: 200px']);
                break;
            case ColumnType::DATE:
                $field = new Field\Date($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case ColumnType::TIME:
                $field = new Field\Time($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case ColumnType::DATETIME:
                $field = new Field\Datetime($form_column_name, [$column_view_name]);
                $field->options(['useCurrent' => false]);
                break;
            case ColumnType::SELECT:
            case ColumnType::SELECT_VALTEXT:
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleSelect($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Select($form_column_name, [$column_view_name]);
                }
                // create select
                $field->options($column->createSelectOptions());
                break;
            case ColumnType::SELECT_TABLE:
            case ColumnType::USER:
            case ColumnType::ORGANIZATION:
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleSelect($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Select($form_column_name, [$column_view_name]);
                }

                // get select_target_table
                if ($column->column_type == ColumnType::SELECT_TABLE) {
                    $select_target_table_id = array_get($options, 'select_target_table');
                    if (isset($select_target_table_id)) {
                        $select_target_table = CustomTable::find($select_target_table_id) ?? null;
                    } else {
                        $select_target_table = null;
                    }
                } elseif ($column->column_type == SystemTableName::USER) {
                    $select_target_table = CustomTable::getEloquent(SystemTableName::USER);
                } elseif ($column->column_type == SystemTableName::ORGANIZATION) {
                    $select_target_table = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
                }

                $field->options(function ($val) use ($select_target_table, $custom_table, $form_column_name) {
                    // get DB option value
                    return $select_target_table->getOptions($val, $custom_table);
                });
                if(isset($select_target_table)){
                    $ajax = $select_target_table->getOptionAjaxUrl() ?? null;
                }
                if (isset($ajax)) {
                    $field->attribute([
                        'data-add-select2' => $column_view_name,
                        'data-add-select2-ajax' => $ajax
                    ]);
                }
                // add table info
                $field->attribute(['data-target_table_name' => array_get($select_target_table, 'table_name')]);
                break;
            case ColumnType::YESNO:
                $field = new ExmentField\SwitchBoolField($form_column_name, [$column_view_name]);
                break;
            case ColumnType::BOOLEAN:
                $field = new Field\SwitchField($form_column_name, [$column_view_name]);
                // set options
                $states = [
                    'on'  => ['value' => array_get($options, 'true_value'), 'text' => array_get($options, 'true_label')],
                    'off' => ['value' => array_get($options, 'false_value'), 'text' => array_get($options, 'false_label')],
                ];
                $field->states($states);
                break;
            case ColumnType::AUTO_NUMBER:
                $field = new ExmentField\Display($form_column_name, [$column_view_name]);
                if(!isset($id)){
                    $field->default(exmtrans('custom_value.auto_number_create'));
                }
                break;
            case ColumnType::IMAGE:
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleImage($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\Image($form_column_name, [$column_view_name]);
                }
                // set file options
                $field->options(
                    static::getFileOptions($custom_table, $column, $id)
                )->removable();

                // set filename rule
                $field->move($custom_table->table_name);
                $field->name(function ($file) {
                    // set fileinfo
                    return FormHelper::setFileInfo($this, $file);
                });
                break;
            case ColumnType::FILE:
                if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                    $field = new Field\MultipleFile($form_column_name, [$column_view_name]);
                } else {
                    $field = new Field\File($form_column_name, [$column_view_name]);
                }
                // set file options
                $field->options(
                    array_merge(
                        static::getFileOptions($custom_table, $column, $id)
                    //, ['showPreview' => false]
                    )
                )->removable();
                
                // set filename rule
                $field->move($custom_table->table_name);
                $field->name(function ($file) {
                    return FormHelper::setFileInfo($this, $file);
                });
                break;
            default:
                $field = new Field\Text($form_column_name, [$column_view_name]);
                break;
        }
        
            // setting options --------------------------------------------------
            // placeholder
            if (array_key_value_exists('placeholder', $options)) {
                $field->placeholder(array_get($options, 'placeholder'));
            }

            // default
            if (array_key_value_exists('default', $options)) {
                $field->default(array_get($options, 'default'));
            }

            // number_format
            if (boolval(array_get($options, 'number_format'))) {
                $field->attribute(['number_format' => true]);
            }

            // // readonly
            if (boolval(array_get($form_column_options, 'view_only'))) {
                $field->attribute(['readonly' => true]);
            }

            // min, max(numeric only)
            if (in_array($column->column_type, [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY])) {
                if (isset($options) && !is_null(array_get($options, 'number_min'))) {
                    $field->attribute(['min' => array_get($options, 'number_min')]);
                }
                if (isset($options) && !is_null(array_get($options, 'number_max'))) {
                    $field->attribute(['max' => array_get($options, 'number_max')]);
                }
            }
        
            // decimal_digit
            if (in_array($column->column_type, [ColumnType::DECIMAL, ColumnType::CURRENCY])) {
                if (isset($options) && !is_null(array_get($options, 'decimal_digit'))) {
                    $field->attribute(['decimal_digit' => array_get($options, 'decimal_digit')]);
                }
            }
        
            // required
            // ignore auto_number. because auto_number is saved flow.
            if (boolval(array_get($options, 'required')) && $column->column_type != 'auto_number') {
                $field->required();
            } else {
                $field->rules('nullable');
            }
    
            // set validates
            $validate_options = [];
            $validates = static::getColumnValidates($custom_table, $column, $id, $validate_options);
            // set validates
            if (count($validates)) {
                $field->rules($validates);
            }
    
            // set help string using result_options
            $help = null;
            $help_regexes = array_get($validate_options, 'help_regexes');
            if (array_key_value_exists('help', $options)) {
                $help = array_get($options, 'help');
            }
            if (isset($help_regexes)) {
                $help .= sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes));
            }
            if (isset($help)) {
                $field->help(esc_html($help));
            }
        }

        // set column type
        $field->attribute(['data-column_type' => $column->column_type]);
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
        
        $validates = [];
        // setting options --------------------------------------------------
        
        // unique
        if (boolval(array_get($options, 'unique')) && !boolval(array_get($options, 'multiple_enabled'))) {
            // add unique field
            $unique_table_name = getDBTableName($table_obj); // database table name
            $unique_column_name = "value->".array_get($custom_column, 'column_name'); // column name
            
            $uniqueRules = [$unique_table_name, $unique_column_name];
            // create rules.if isset id, add
            $uniqueRules[] = (isset($value_id) ? "$value_id" : "");
            $uniqueRules[] = 'id';
            // and ignore data deleted_at is NULL 
            $uniqueRules[] = 'deleted_at';
            $uniqueRules[] = 'NULL';
            $rules = "unique:".implode(",", $uniqueRules);
            // add rules
            $validates[] = $rules;
        }

        // regex rules
        $help_regexes = [];
        if (array_key_value_exists('available_characters', $options)) {
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
                $validates[] = 'regex:/^['.implode("", $regexes).']*$/';
            }
        }
        
        // set help_regexes to result_options
        if (count($help_regexes) > 0) {
            $result_options['help_regexes'] = $help_regexes;
        }

        // text validate
        if (in_array($column_type, [ColumnType::TEXT,ColumnType::TEXTAREA])) {
            // string_length
            if (array_get($options, 'string_length')) {
                $validates[] = 'max:'.array_get($options, 'string_length');
            }
        }

        // number attribute
        if (in_array($column_type, [ColumnType::INTEGER,ColumnType::DECIMAL, ColumnType::CURRENCY])) {
            // value size
            if (array_get($options, 'number_min')) {
                $validates[] = 'min:'.array_get($options, 'number_min');
            }
            if (array_get($options, 'number_max')) {
                $validates[] = 'max:'.array_get($options, 'number_max');
            }
        }

        if ($column_type == ColumnType::INTEGER) {
            $validates[] = new Validator\IntegerCommaRule;
        }
        if (in_array($column_type, [ColumnType::DECIMAL, ColumnType::CURRENCY])) {
            $validates[] = new Validator\DecimalCommaRule;
        }

        return $validates;
    }

    protected static function getFileOptions($custom_table, $custom_column, $id)
    {
        return 
            [
            'showCancel' => false,
            'deleteUrl' => admin_urls('data', $custom_table->table_name, $id, 'filedelete'),
            'deleteExtraData'      => [
                Field::FILE_DELETE_FLAG         => $custom_column->column_name,
                '_token'                         => csrf_token(),
                '_method'                        => 'PUT',
            ]
            ];
    }

    /**
     *
     */
    public static function setFileInfo($field, $file)
    {
        // get local filename
        $dirname = $field->getDirectory();
        $filename = $file->getClientOriginalName();
        $local_filename = ExmentFile::getUniqueFileName($dirname, $filename);
        // save file info
        $exmentfile = ExmentFile::saveFileInfo($dirname, $filename, $local_filename);

        // set request session to save this custom_value's id and type into files table.
        $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
        $file_uuids[] = ['uuid' => $exmentfile->uuid, 'column_name' => $field->column()];
        System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);
        
        // return filename
        return $exmentfile->local_filename;
    }
}

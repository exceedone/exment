<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Show;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Services\PluginInstaller;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

trait CustomValueForm
{
    
    /**
     * set custom form columns
     */
    protected function setCustomFormColumns($form, $custom_form_block)
    {
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            $form_column_options = $form_column->options;
            switch ($form_column->form_column_type) {
                case Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN:
                    $column = $form_column->custom_column;
                    $options = $column->options;
                    $column_name = $column->column_name;
                    $column_view_name = $column->column_view_name;
                
                    // if hidden setting, add hidden field and continue
                    if(isset($form_column_options) && boolval(array_get($form_column_options, 'hidden'))){
                        $form->pushField(new Field\Hidden($column_name));
                        continue 2;
                    }

                    switch ($column->column_type) {
                        case 'text':
                            $field = new Field\Text($column_name, [$column_view_name]);
                            break;
                        case 'textarea':
                            $field = new Field\Textarea($column_name, [$column_view_name]);
                            break;
                        case 'url':
                            $field = new Field\Url($column_name, [$column_view_name]);
                            break;
                        case 'email':
                            $field = new Field\Email($column_name, [$column_view_name]);
                            break;
                        case 'password':
                            $field = new Field\Password($column_name, [$column_view_name]);
                            break;
                        case 'integer':
                            $field = new ExmentField\Number($column_name, [$column_view_name]);
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
                            $field = new Field\Text($column_name, [$column_view_name]);
                            if (isset($options) && !is_null(array_get($options, 'number_min'))) {
                                $field->attribute(['min' => array_get($options, 'number_min')]);
                            }
                            if (isset($options) && !is_null(array_get($options, 'number_max'))) {
                                $field->attribute(['max' => array_get($options, 'number_max')]);
                            }
                            break;
                        case 'date':
                            $field = new Field\Date($column_name, [$column_view_name]);
                            $field->options(['useCurrent' => false]);
                            break;
                        case 'time':
                            $field = new Field\Time($column_name, [$column_view_name]);
                            $field->options(['useCurrent' => false]);
                            break;
                        case 'datetime':
                            $field = new Field\Datetime($column_name, [$column_view_name]);
                            $field->options(['useCurrent' => false]);
                            break;
                        case 'select':
                        case 'select_valtext':
                            if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                                $field = new Field\MultipleSelect($column_name, [$column_view_name]);
                            } else {
                                $field = new Field\Select($column_name, [$column_view_name]);
                            }
                            // create select
                            $field->options(
                                createSelectOptions(array_get($options, $column->column_type == 'select' ? 'select_item' : 'select_item_valtext'), 
                                $column->column_type == 'select_valtext')
                            );
                            break;
                        case 'select_table':
                        case 'user':
                        case 'organization':
                            if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                                $field = new Field\MultipleSelect($column_name, [$column_view_name]);
                            } else {
                                $field = new Field\Select($column_name, [$column_view_name]);
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
                            $field = new ExmentField\SwitchBoolField($column_name, [$column_view_name]);
                            break;
                        case 'boolean':
                            $field = new Field\SwitchField($column_name, [$column_view_name]);
                            // set options
                            $states = [
                                'on'  => ['value' => array_get($options, 'true_value'), 'text' => array_get($options, 'true_label')],
                                'off' => ['value' => array_get($options, 'false_value'), 'text' => array_get($options, 'false_label')],
                            ];
                            $field->states($states);
                            break;
                        case 'auto_number':
                            $field = new ExmentField\Display($column_name, [$column_view_name]);
                            break;
                        case 'image':
                            if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                                $field = new Field\MultipleImage($column_name, [$column_view_name]);
                            } else {
                                $field = new Field\Image($column_name, [$column_view_name]);
                            }
                            break;
                        case 'file':
                            if (isset($options) && boolval(array_get($options, 'multiple_enabled'))) {
                                $field = new Field\MultipleFile($column_name, [$column_view_name]);
                            } else {
                                $field = new Field\File($column_name, [$column_view_name]);
                            }
                            break;
                        default:
                            $field = new Field\Text($column_name, [$column_view_name]);
                            break;
                    }
                    break;
                case Define::CUSTOM_FORM_COLUMN_TYPE_OTHER:
                    $options = [];
                    $form_column_obj = array_get(Define::CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE, $form_column->form_column_target_id);
                    switch (array_get($form_column_obj, 'column_name')) {
                        case 'header':
                            $field = new ExmentField\Header(array_get($form_column_options, 'text'));
                            $field->hr();
                            break;
                        case 'explain':
                            $field = new ExmentField\Description(array_get($form_column_options, 'text'));
                            break;
                        case 'html':
                            $field = new Field\Html(array_get($form_column_options, 'html'));
                            break;
                        default:
                            continue;
                            break;
                    }
                break;
            }

            // setting options --------------------------------------------------
            // required
            if (isset($options) && boolval(array_get($options, 'required')) && !in_array(get_class($field), ["Encore\\Admin\\Form\\Field\\Display", ExmentField\Display::class])) {
                $field->rules('required');
            }else{
                $field->rules('nullable');
            }

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

            // regex rules
            $help_regexes = [];
            if (array_has_value($options, 'available_characters')) {
                $available_characters = array_get($options, 'available_characters');
                $regexes = [];
                // add regexes using loop
                foreach($available_characters as $available_character){
                    switch($available_character){
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
                if(count($regexes) > 0){
                    $field->rules('regex:/['.implode("", $regexes).']/');
                }
            }

            $help = null;
            if (array_has_value($options, 'help')) {
                $help = array_get($options, 'help');
            }
            if(count($help_regexes) > 0){
                $help .= sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes));
            }
            if(isset($help)){
                $field->help($help);
            }

            // push field to form
            $form->pushField($field);
        }
    }

    /**
     * set custom form columns
     */
    protected function setCustomForEvents(&$calc_formula_array, &$changedata_array)
    {
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            foreach ($custom_form_block->custom_form_columns as $form_column) {
                if ($form_column->form_column_type != Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN) {
                    continue;
                }
                $column = $form_column->custom_column;
                $form_column_options = $form_column->options;
                $options = $column->options;
                
                // set calc rule for javascript
                if (array_has_value($options, 'calc_formula')) {
                    $this->setCalcFormulaArray($column, $options, $calc_formula_array);
                }
                // data changedata
                // if set form_column_options changedata_target_column_id, and changedata_column_id
                if (array_has_value($form_column_options, 'changedata_target_column_id') && array_has_value($form_column_options, 'changedata_column_id')) {
                    ///// set changedata info
                    $this->setChangeDataArray($column, $form_column_options, $options, $changedata_array);
                }
            }
        }
    }


    protected function manageFormSaving($form)
    {
        // before saving
        $form->saving(function ($form) {
            PluginInstaller::pluginPreparing($this->plugins, 'saving');
        });
    }

    protected function manageFormSaved($form)
    {
        $custom_table = $this->custom_table;
        $custom_form_columns = $this->custom_form->custom_form_columns;
        $form->saved(function ($form) use($custom_table, $custom_form_columns) {
            PluginInstaller::pluginPreparing($this->plugins, 'saved');
            
            // change value if necessary
            $update_flg = false;
            $model = $form->model();
            $id = $model->id;
            
            // loop for form columns
            foreach ($custom_form_columns as $custom_form_column) {
                // custom column
                $custom_column = $custom_form_column->custom_column;
                $column_name = $custom_column->column_name;

                switch ($custom_column->column_type) {
                    // if column type is auto_number, set auto number.
                    case 'auto_number':
                        // already set value, break
                        if(!is_null($model->getValue($column_name))){
                            break;
                        }
                        $options = $custom_column->options;
                        if (!isset($options)) {
                            break;
                        }
                        if (array_get($options, 'auto_number_type') == 'format') {
                            $auto_number = $this->createAutoNumberFormat($model, $id, $options);
                        }
                        // if auto_number_type is random25, set value
                        if (array_get($options, 'auto_number_type') == 'random25') {
                            $auto_number = make_licensecode();
                        }
                        // if auto_number_type is UUID, set value
                        if (array_get($options, 'auto_number_type') == 'random32') {
                            $auto_number = make_uuid();
                        }
                        $model->setValue($column_name, $auto_number);
                        $update_flg = true;
                        break;
                }
            }

            if($update_flg){
                $model->saveOrFail();
            }
            
            // get target custom_value's value_authoritable
            if(($form->model()->getAuthoritable(Define::SYSTEM_TABLE_NAME_USER)->count() == 0
                || $form->model()->getAuthoritable(Define::SYSTEM_TABLE_NAME_ORGANIZATION)->count() == 0)
                && !in_array($custom_table->table_name, [Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])
            ){
                ///// if all user and org is 0, add userself authority
                // get authority where custom_value_edit is 1
                $authority = Authority::where('authority_type', Define::AUTHORITY_TYPE_VALUE)
                    ->where("permissions->".Define::AUTHORITY_VALUE_CUSTOM_VALUE_EDIT, "1")
                    ->first();

                DB::table('value_authoritable')
                    ->insert([
                        'related_id' => Admin::user()->base_user_id,
                        'related_type' => Define::SYSTEM_TABLE_NAME_USER,
                        'morph_id' => $form->model()->id,
                        'morph_type' => $custom_table->table_name,
                        'authority_id' => $authority->id,
                    ]);
            }
        });
    }

    protected function manageFormToolButton($form, $id, $isNew, $custom_table, $custom_form, $isButtonCreate, $listButton)
    {
        $form->tools(function (Form\Tools $tools) use ($form, $id, $isNew, $custom_table, $custom_form, $isButtonCreate, $listButton) {        // Disable back btn.

            // if one_record_flg, disable list
            if ($custom_table->one_record_flg) {
                $tools->disableListButton();
                $tools->disableDelete();
                $tools->disableView();
            }

            // if user only view, disable delete and view
            else if (!Admin::user()->hasPermissionEditData($id, $custom_table->table_name)) {
                $tools->disableDelete();
                $tools->disableView();
                $form->disableViewCheck();
            }

            if ($listButton !== null && (count($listButton) > 0 && ($isButtonCreate && $id === null) || (!$isButtonCreate && $id !== null))) {
                $index = 0;
                foreach ($listButton as $buttonItem) {
                    $index++;
                    $button = '<a class="btn btn-sm btn-info" onclick="onPluginClick'.$index.'()"><i class="fa fa-archive"></i>&nbsp;'.$buttonItem->plugin_view_name.'</a>';
                    $tools->add($button);
                    $ajaxContainer = '<script>
                    function onPluginClick'.$index.'() {
                          $.ajax({
                               type: "POST",
                               url: '.admin_base_path('data/'.$custom_table->table_name.'/onPluginClick').',
                               data:{_token: LA.token,plugin_name:"'.$buttonItem->plugin_name.'"},
                               success:function(reponse) {
                                toastr.success(reponse);
                               }
                          });
                     }
                </script>';
                    $tools->add($ajaxContainer);
                }
            }

            $tools->add((new Tools\GridChangePageMenu('data', $custom_table, false))->render());
        });
    }
    
    /** 
     * create show form list
     */
    protected function createShowForm($id = null)
    {
        //PluginInstaller::pluginPreparing($this->plugins, 'loading');
        return Admin::show($this->getModelNameDV()::findOrFail($id), function (Show $show) use ($id) {
            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                foreach ($custom_form_block->custom_form_columns as $form_column) {
                    $column = $form_column->custom_column;
                    $options = $column->options;
                    $show->field(getColumnName($column, true), $column->column_view_name);
                }
            }

            // if user only view permission, disable delete and view
            if (!Admin::user()->hasPermissionEditData($id, $this->custom_table->table_name)) {
                $show->panel()->tools(function ($tools) {
                    $tools->disableEdit();
                    $tools->disableDelete();
                });
            }
        });
    }

    /**
     * Create Auto Number value using format.
     */
    protected function createAutoNumberFormat($model, $id, $options){
        // get format
        $format = array_get($options, "auto_number_format");
        try {
            // check string
            preg_match_all('/\${(.*?)\}/', $format, $matches);
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    try{
                        $match = strtolower($matches[1][$i]);
                    
                        // get length
                        $length_array = explode(":", $match);
                        
                        ///// id
                        if (strpos($match, "id") !== false) {
                            // replace add zero using id.
                            if (count($length_array) > 1) {
                                $id_string = sprintf('%0'.$length_array[1].'d', $id);
                            } else {
                                $id_string = $id;
                            }
                            $format = str_replace($matches[0][$i], $id_string, $format);
                        }

                        ///// Year
                        elseif (strpos($match, "y") !== false) {
                            $str = Carbon::now()->year;
                            $format = str_replace($matches[0][$i], $str, $format);
                        }

                        ///// Month
                        elseif (strpos($match, "m") !== false) {
                            $str = Carbon::now()->month;
                            // if user input length
                            if (count($length_array) > 1) {
                                $length = $length_array[1];
                            }
                            // default 2
                            else {
                                $length = 2;
                            }
                            $format = str_replace($matches[0][$i], sprintf('%0'.$length.'d', $str), $format);
                        }
                    
                        ///// Day
                        elseif (strpos($match, "d") !== false) {
                            $str = Carbon::now()->day;
                            // if user input length
                            if (count($length_array) > 1) {
                                $length = $length_array[1];
                            }
                            // default 2
                            else {
                                $length = 2;
                            }
                            $format = str_replace($matches[0][$i], sprintf('%0'.$length.'d', $str), $format);
                        }

                        ///// value
                        elseif (strpos($match, "value") !== false) {
                            // get value from model
                            if (count($length_array) <= 1) {
                                $str = '';
                            } else {
                                $str = $model->getValue($length_array);
                            }
                            $format = str_replace($matches[0][$i], $str, $format);
                        }
                    } catch(Exception $e) {
                    }
                }
            }
        } catch(Exception $e) {

        }
        return $format;
    }
    
    /**
     * Create calc formula info.
     */
    protected function setCalcFormulaArray($column, $options, &$calc_formula_array){
        if(is_null($calc_formula_array)){$calc_formula_array = [];}
        // get format for calc formula
        $option_calc_formulas = array_get($options, "calc_formula");
        if(!is_array($option_calc_formulas) && is_json($option_calc_formulas)){
            $option_calc_formulas = json_decode($option_calc_formulas, true);
        }

        // keys for calc trigger on display
        $keys = [];
        // loop $option_calc_formulas and get column_name
        foreach($option_calc_formulas as &$option_calc_formula){
            if(array_get($option_calc_formula, 'type') != 'dynamic'){
                continue;
            }
            // set column name
            $formula_column = CustomColumn::find(array_get($option_calc_formula, 'val'));
            $key = $formula_column->column_name ?? null;
            if(!isset($key)){continue;}
            $keys[] = $key;
            // set $option_calc_formula val using key
            $option_calc_formula['val'] = $key;
        }

        // loop for $keys and set $calc_formula_array
        foreach($keys as $key){
            // if not exists $key in $calc_formula_array, set as array
            if(!array_key_exists($key, $calc_formula_array)){
                $calc_formula_array[$key] = [];
            }
            // set $calc_formula_array
            $calc_formula_array[$key][] = [
                'options' => $option_calc_formulas,
                'to' => $column->column_name
            ];
        }
    }

    /**
     * 
     */
    protected function setChangeDataArray($column, $form_column_options, $options, &$changedata_array){
        // get target and column info from form option
        $changedata_target_column_id = array_get($form_column_options, 'changedata_target_column_id');
        $changedata_column_id = array_get($form_column_options, 'changedata_column_id');
        
        // get getting target model name
        $changedata_target_column = CustomColumn::find($changedata_target_column_id);
        $changedata_target_table = CustomTable::find(array_get($changedata_target_column, 'options.select_target_table'));

        // get table column. It's that when get model data, copied from column
        $changedata_column = CustomColumn::find($changedata_column_id);

        // if not exists $changedata_target_column->column_name in $changedata_array
        if(!array_key_exists($changedata_target_column->column_name, $changedata_array)){
            $changedata_array[$changedata_target_column->column_name] = [];
        }
        // push changedata column from and to column name
        $changedata_array[$changedata_target_column->column_name][] = [
            'target_table' => $changedata_target_table->table_name,
            'from' => $changedata_column->column_name,
            'to' => $column->column_name,
        ];
    }
}

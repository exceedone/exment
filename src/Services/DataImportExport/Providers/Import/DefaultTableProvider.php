<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Validator;
use Carbon\Carbon;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\ColumnItems\ParentItem;
use Exceedone\Exment\Validator\CustomValueRule;
use Exceedone\Exment\Validator\EmptyRule;
use Illuminate\Validation\Rule;

class DefaultTableProvider extends ProviderBase
{
    protected $primary_key;
    
    protected $filter;

    /**
     * Select Table not found errors
     *
     * @var array
     */
    protected $selectTableNotFounds;

    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');

        $this->primary_key = array_get($args, 'primary_key', 'id');

        $this->filter = array_get($args, 'filter');

        $this->selectTableNotFounds = [];
    }

    /**
     * get data and object.
     * set matched model data
     */
    public function getDataObject($data, $options = [])
    {
        ///// get all table columns
        $custom_columns = $this->custom_table->custom_columns;

        $results = [];
        $headers = [];
        foreach ($data as $line_no => $value) {
            // get header if $line_no == 0
            if ($line_no == 0) {
                $headers = $value;
                continue;
            }
            // continue if $line_no == 1
            elseif ($line_no == 1) {
                continue;
            }

            // combine value
            $value_custom = array_combine($headers, $value);

            // filter data
            if ($this->filterData($value_custom)) {
                continue;
            }

            ///// convert data first.
            $value_custom = $this->dataProcessingFirst($custom_columns, $value_custom, $line_no, $options);

            // get model
            $modelName = getModelName($this->custom_table);
            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new $modelName;
            }
            // if exists, firstOrNew
            else {
                //*Replace "." to "->" for json value
                $model = $modelName::withTrashed()->firstOrNew([str_replace(".", "->", $this->primary_key) => $primary_value]);
            }
            if (!isset($model)) {
                continue;
            }
            $model->saved_notify(false);

            $results[] = ['data' => $value_custom, 'model' => $model];
        }

        return $results;
    }
    
    /**
     * validate imported all data.
     * @param $data
     * @return array
     */
    public function validateImportData($dataObjects)
    {
        ///// get all table columns
        $validate_columns = $this->custom_table->custom_columns;
        
        $error_data = [];
        $success_data = [];
        foreach ($dataObjects as $line_no => $value) {
            $check = $this->validateDataRow($line_no, $value, $validate_columns);
            if ($check === true) {
                array_push($success_data, $value);
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }

        // loop target select table error
        foreach ($this->selectTableNotFounds as $selectTableNotFound) {
            $error_data[] = $selectTableNotFound;
        }

        return [$success_data, $error_data];
    }
    
    /**
     * validate data row
     * @param $action
     * @param $data
     * @return array
     */
    public function validateDataRow($line_no, $dataAndModel, $validate_columns)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');

        // get fields for validation
        $fields = [];
        $customAttributes = [];
        foreach ($validate_columns as $validate_column) {
            $fields[] = FormHelper::getFormField($this->custom_table, $validate_column, array_get($model, 'id'), null, 'value.');

            // get display name
            $customAttributes['value.' . $validate_column->column_name] = "{$validate_column->column_view_name}({$validate_column->column_name})";
        }
        
        // create parent type validation array
        $custom_relation_parent = CustomRelation::getRelationByChild($this->custom_table, RelationType::ONE_TO_MANY);
        $custom_table_parent = ($custom_relation_parent ? $custom_relation_parent->parent_custom_table : null);
        
        $parent_id_rules = isset($custom_table_parent) ? ['nullable', 'numeric', new CustomValueRule($custom_table_parent)] : [new EmptyRule];
        $parent_type_rules = isset($custom_table_parent) ? ['nullable', "in:". $custom_table_parent->table_name] : [new EmptyRule];

        // create common validate rules.
        $rules = [
            'id' => ['nullable', 'numeric'],
            'parent_id' => $parent_id_rules,
            'parent_type' => $parent_type_rules,
            'suuid' => ['nullable', 'regex:/^[a-z0-9]{20}$/'],
            'created_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
            'deleted_at' => ['nullable', 'date'],
        ];
        foreach($rules as $key => $rule){
            $customAttributes[$key] = exmtrans("common.$key") . "($key)";
        }

        // foreach for field validation rules
        foreach ($fields as $field) {
            // get field validator
            $field_validator = $field->getValidator($data);
            if (!$field_validator) {
                continue;
            }
            // get field rules
            $field_rules = $field_validator->getRules();

            // merge rules
            $rules = array_merge($field_rules, $rules);
        }
        
        // execute validation
        $validator = Validator::make(array_dot_reverse($data), $rules, [], $customAttributes);
        if ($validator->fails()) {
            // create error message
            $errors = [];
            foreach ($validator->errors()->messages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), implode(',', $message));
            }
            return $errors;
        }
        return true;
    }

    /**
     * @param $data
     * @return array
     */
    public function dataProcessing($data)
    {
        $data_custom = [];
        $value_arr = [];
        foreach ($data as $key => $value) {
            if (strpos($key, "value.") !== false) {
                $new_key = str_replace('value.', '', $key);
                $value_arr[$new_key] = is_nullorempty($value) ? null : preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $value);
            } else {
                $data_custom[$key] = is_nullorempty($value) ? null : $value;
            }
        }
        $data_custom['value'] = $value_arr;
        return $data_custom;
    }

    /**
     * Data processing before getting model using imported data
     *
     * @param $data
     * @return array
     */
    public function dataProcessingFirst($custom_columns, $data, $line_no, $options = [])
    {
        foreach ($data as $key => &$value) {
            if (strpos($key, "value.") !== false) {
                $new_key = str_replace('value.', '', $key);
                // get target column
                $target_column = $custom_columns->first(function ($custom_column) use ($new_key) {
                    return array_get($custom_column, 'column_name') == $new_key;
                });
                if (!isset($target_column)) {
                    continue;
                }

                if (ColumnType::isMultipleEnabled(array_get($target_column, 'column_type'))
                    && boolval(array_get($target_column, 'options.multiple_enabled'))) {
                    $value = explode(",", $value);
                }

                // convert target key's id
                if (isset($value)) {
                    if (array_has($options, 'setting')) {
                        $s = collect($options['setting'])->filter(function ($s) use ($key) {
                            return isset($s['target_column_name']) && $s['column_name'] == $key;
                        })->first();
                    }
                    if (isset($target_column->column_item)) {
                        $target_table = isset($target_column->select_target_table) ? $target_column->select_target_table : $target_column->custom_table;
                        $this->getImportColumnValue($data, $key, $value, $target_column->column_item, $target_column->column_item->label(), $s ?? null, $target_table, $line_no);
                    }
                }
            } elseif ($key == Define::PARENT_ID_NAME && isset($value)) {
                // convert target key's id
                if (array_has($options, 'setting')) {
                    $s = collect($options['setting'])->filter(function ($s) use ($key) {
                        return isset($s['target_column_name']) && $s['column_name'] == Define::PARENT_ID_NAME;
                    })->first();
                }

                $target_table = CustomTable::getEloquent(array_get($data, 'parent_type'));
                $parent_item = ParentItem::getItem($target_table);
                if (isset($parent_item)) {
                    $this->getImportColumnValue($data, $key, $value, $parent_item, $target_table->table_view_name, $s ?? null, $target_table, $line_no);
                }
            }
        }
        return $data;
    }

    /**
     * import data
     */
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        // select $model using primary key and value
        $primary_value = array_get($data, $this->primary_key);
        // if not exists, new instance
        if (is_nullorempty($primary_value)) {
            $isCreate = true;
        }
        // if exists, firstOrNew
        else {
            $isCreate = !$model->exists;
        }
        if (!isset($model)) {
            return;
        }

        // loop for data
        foreach ($data as $dkey => $dvalue) {
            $dvalue = is_nullorempty($dvalue) ? null : $dvalue;
            // if not exists column, continue
            if (!in_array($dkey, ['id', 'suuid', 'parent_id', 'parent_type', 'value', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            // setvalue function if key is value
            if ($dkey == 'value') {
                // loop dvalue
                foreach ($dvalue as $dvalueKey => $dvalueValue) {
                    $model->setValue($dvalueKey, $dvalueValue);
                }
            }
            // if timestamps
            elseif (in_array($dkey, ['created_at', 'updated_at', 'deleted_at'])) {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                // if not create and created_at, continue(because time back)
                if (!$isCreate && $dkey == 'created_at') {
                    continue;
                }
                // set as date
                $model->{$dkey} = Carbon::parse($dvalue);
            }
            // if id or suuid
            elseif (in_array($dkey, ['id', 'suuid'])) {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                $model->{$dkey} = $dvalue;
            }
            // else, set
            else {
                $model->{$dkey} = $dvalue;
            }
        }

        // if not has deleted_at value, remove deleted_at value
        if (!array_key_value_exists('deleted_at', $data)) {
            $model->deleted_at = null;
        }

        // save model
        $model->save();

        return $model;
    }

    /**
     * check filter data
     */
    protected function filterData($value_custom)
    {
        $is_filter = false;
        if (is_array($this->filter) && count($this->filter) > 0) {
            foreach ($this->filter as $key => $list) {
                $value = array_get($value_custom, $key);
                if (!isset($value) || !in_array($value, $list)) {
                    $is_filter = true;
                    break;
                }
            }
        }
        return $is_filter;
    }

    /**
     * get column import value. if error, set message
     *
     * @return void
     */
    protected function getImportColumnValue(&$data, $key, &$value, $column_item, $column_view_name, $setting, $target_table, $line_no)
    {
        $base_value = $value;
        $importValue = $column_item->getImportValue($value, $setting ?? null);

        if (!isset($importValue)) {
            return;
        }

        // if skip column, remove from data, and return
        if (boolval(array_get($importValue, 'skip'))) {
            array_forget($data, $key);
            return;
        }

        // if not found, set error
        if (!boolval(array_get($importValue, 'result'))) {
            $message = isset($importValue['message']) ? $importValue['message'] : exmtrans('custom_value.import.message.select_table_not_found', [
                'column_view_name' => $column_view_name,
                'value' => is_array($base_value) ? implode(exmtrans('common.separate_word'), $base_value) : $base_value,
                'target_table_name' => $target_table->table_view_name
            ]);
            $this->selectTableNotFounds[] =  sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no-1), $message);
        }
        $value = array_get($importValue, 'value');
    }
}

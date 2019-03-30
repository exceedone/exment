<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Services\FormHelper;
use Illuminate\Http\Request;
use Validator;
use Carbon\Carbon;

abstract class DataImporterBase
{
    protected $custom_table;
    protected $relations;
    protected $accept_extension = '';
    public function __construct($custom_table)
    {
        $this->custom_table = $custom_table;
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);
    }

    /**
     * @param $request
     * @return mixed|void error message or success message etc...
     */
    public function import($request)
    {
        set_time_limit(240);
        // validate request
        if ($request instanceof Request) {
            $validateRequest = $this->validateRequest($request);
            if ($validateRequest !== true) {
                return [
                    'result' => false,
                    //'toastr' => exmtrans('common.message.import_error'),
                    'errors' => $validateRequest,
                ];
            }
            $select_primary_key = $request->select_primary_key;
        } else {
            $select_primary_key = 'id';
        }

        // get table data
        $datalist = $this->getDataTable($request);
        
        // get target data and model list
        
        foreach ($datalist as $table_name => $data) {
            // if data is n:n data, get data as pivot
            if (isset($data['relation']) && $data['relation']->relation_type == RelationType::MANY_TO_MANY) {
                $data_pivots[] = $this->getPivotData($data['data'], $data['relation']);
                continue;
            }

            $target_table = $data['custom_table'];
            $dataAndModels = $this->getDataAndModels($target_table, $data['data'], $select_primary_key);
            // validate data
            list($data_import, $error_data) = $this->validateData($target_table, $dataAndModels);
        
            // if has error data, return error data
            if (count($error_data) > 0) {
                return [
                    'result' => false,
                    'toastr' => exmtrans('common.message.import_error'),
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => implode("\r\n", $error_data)]],
                ];
            }
            $data_imports[] = $data_import;
        }

        if (isset($data_imports)) {
            foreach ($data_imports as $data_import) {
                // execute imoport
                foreach ($data_import as $index => &$row) {
                    $row['data'] = $this->dataProcessing(array_get($row, 'data'));
                    $this->dataImportFlow($row, $select_primary_key);
                }
            }
        }

        if (isset($data_pivots)) {
            foreach ($data_pivots as $data_pivot) {
                // execute imoport
                foreach ($data_pivot as $index => &$row) {
                    $this->dataImportPivot($row);
                }
            }
        }

        // if success, return result and toastor messsage
        return [
            'result' => true,
            'toastr' => exmtrans('common.message.import_success')
        ];
    }

    /**
     * @param $request
     * @return bool
     */
    public function validateRequest($request)
    {
        //validate
        $rules = [
            'custom_table_file' => 'required|file',
            'select_primary_key' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $validator->errors()->messages();
        }

        // file validation.
        // (↑"$rules" always error by mimes because uploaded by ajax??)
        $file = $request->file('custom_table_file');
        $validator = Validator::make(
            [
                'file'      => $file,
                'custom_table_file' => strtolower($file->getClientOriginalExtension()),
            ],
            [
                'file'          => 'required',
                'custom_table_file'      => 'required|in:'.$this->accept_extension,
            ],
            [
                'custom_table_file' => \Lang::get('validation.mimes')
            ]
        );
        if ($validator->fails()) {
            // return errors as custom_table_file.
            return $validator->errors()->messages();
        }

        return true;
    }

    /**
     * get pivot data for n:n
     */
    protected function getPivotData($data, $relation)
    {
        $results = [];
        $headers = [];
        foreach ($data as $key => $value) {
            // get header if $key == 0
            if ($key == 0) {
                $headers = $value;
                continue;
            }
            // continue if $key == 1
            elseif ($key == 1) {
                continue;
            }

            // combine value
            $value_custom = array_combine($headers, $value);
            $delete = boolval(array_get($value_custom, 'delete')) || boolval(array_get($value_custom, 'delete_flg'));
            array_forget($value_custom, 'delete');
            array_forget($value_custom, 'delete_flg');
            $results[] = ['data' => $value_custom, 'delete' => $delete, 'relation' => $relation];
        }

        return $results;
    }

    /**
     * get data and model array
     */
    public function getDataAndModels($target_table, $data, $primary_key)
    {
        ///// get all table columns
        $custom_columns = $target_table->custom_columns;

        $results = [];
        $headers = [];
        foreach ($data as $key => $value) {
            // get header if $key == 0
            if ($key == 0) {
                $headers = $value;
                continue;
            }
            // continue if $key == 1
            elseif ($key == 1) {
                continue;
            }

            // combine value
            $value_custom = array_combine($headers, $value);

            ///// convert data first.
            $value_custom = $this->dataProcessingFirst($custom_columns, $value_custom);

            // get model
            $modelName = getModelName($target_table);
            // select $model using primary key and value
            $primary_value = array_get($value_custom, $primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new $modelName;
            }
            // if exists, firstOrNew
            else {
                //*Replace "." to "->" for json value
                $model = $modelName::withTrashed()->firstOrNew([str_replace(".", "->", $primary_key) => $primary_value]);
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
    public function validateData($target_table, $dataAndModels)
    {
        ///// get all table columns
        $validate_columns = $target_table->custom_columns;
        
        $error_data = array();
        $success_data = array();
        foreach ($dataAndModels as $key => $value) {
            $check = $this->validateDataRow($target_table, $key, $value, $validate_columns);
            if ($check === true) {
                array_push($success_data, $value);
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }
        return [$success_data, $error_data];
    }

    /**
     * validate data row
     * @param $action
     * @param $data
     * @return array
     */
    public function validateDataRow($target_table, $line_no, $dataAndModel, $validate_columns)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');

        // get fields for validation
        $fields = [];
        foreach ($validate_columns as $validate_column) {
            $fields[] = FormHelper::getFormField($target_table, $validate_column, array_get($model, 'id'), null, 'value.');
        }
        // create common validate rules.
        $rules = [
            'id' => ['nullable', 'regex:/^[0-9]+$/'],
            'suuid' => ['nullable', 'regex:/^[a-z0-9]{20}$/'],
            'created_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
            'deleted_at' => ['nullable', 'date'],
        ];
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
        $validator = Validator::make(array_dot_reverse($data), $rules);
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
    public function dataProcessingFirst($custom_columns, $data)
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
            }
        }
        return $data;
    }

    /**
     * @param $data
     * @return array
     */
    public function dataProcessing($data)
    {
        $data_custom = array();
        $value_arr = array();
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
     * import data
     */
    public function dataImportFlow($dataAndModel, $primary_key)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        // select $model using primary key and value
        $primary_value = array_get($data, $primary_key);
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
    }

    /**
     * import data (n:n relation)
     */
    public function dataImportPivot($dataPivot)
    {
        $data = array_get($dataPivot, 'data');
        $delete = array_get($dataPivot, 'delete');
        $relation = array_get($dataPivot, 'relation');

        // get database name
        $table_name = $relation->getRelationName();

        // get target id(cannot use Eloquent because not define)
        $id = \DB::table($table_name)
            ->where('parent_id', array_get($data, 'parent_id'))
            ->where('child_id', array_get($data, 'child_id'))
            ->first()->id ?? null;
        
        // if delete
        if (isset($id) && $delete) {
            \DB::table($table_name)->where('id', $id)->delete();
        } elseif (!isset($id)) {
            \DB::table($table_name)->insert($data);
        }
    }


    // Import Modal --------------------------------------------------

    public function importModal()
    {
        $table_name = $this->custom_table->table_name;
        $import_path = admin_base_paths('data', $table_name, 'import');
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->disableReset()
            ->action(admin_base_path('data/'.$table_name.'/import'))
            ;
        $form->modalAttribute('id', 'data_import_modal');
        $form->modalHeader(exmtrans('common.import') . ' - ' . $this->custom_table->table_view_name);

        $form->description(sprintf(exmtrans("custom_value.import.help.description"), getManualUrl('data_import_export?id='.exmtrans('custom_value.import.manual_id'))))
            ->setWidth(8, 3)
            ;

        $form->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:csv,xlsx')
            ->setWidth(8, 3)
            ->addElementClass('custom_table_file')
            ->options(Define::FILE_OPTION)
            ->help(exmtrans('custom_value.import.help.custom_table_file'));
        
        // get import primary key list
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options($this->getPrimaryKeys())
            ->default('id')
            ->setWidth(8, 3)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));

        $form->hidden('select_action')->default('stop');
        // $form->select('select_action', exmtrans('custom_value.import.error_flow'))
        //     ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_ERROR, "custom_value.import.error_options"))
        //     ->default('stop')
        //     ->setWidth(8, 3)
        //     ->addElementClass('select_action')
        //     ->help(exmtrans('custom_value.import.help.error_flow'));
    
        $form->textarea('import_error_message', exmtrans('custom_value.import.import_error_message'))
            ->attribute(['readonly' => true])
            ->setWidth(8, 3)
            ->rows(4)
            ->addElementClass('import_error_message')
            ->help(exmtrans('custom_value.import.help.import_error_message'));
    
        $form->hidden('custom_table_name')->default($table_name);
        $form->hidden('custom_table_suuid')->default($this->custom_table->suuid);
        $form->hidden('custom_table_id')->default($this->custom_table->id);

        return $form->render()->render();
    }
    
    /**
     * get importer model
     */
    public static function getModel($custom_table, $format = null)
    {
        switch ($format) {
            case 'excel':
            case 'xlsx':
                return new ExcelImporter($custom_table);
            default:
                return new CsvImporter($custom_table);
        }
    }

    /**
     * get uploaded file extension
     */
    public static function getFileExtension($request)
    {
        $file = $request->file('custom_table_file');
        if (!isset($file)) {
            return null;
        }
        switch ($file->extension()) {
            case 'xlsx':
                return 'excel';
            case 'csv':
                return 'csv';
        }
        return null;
    }
    /**
     * get table from excel or csv.
     */
    //abstract protected function getDataTable($request);

    
    /**
     * get primary key list.
     */
    protected function getPrimaryKeys()
    {
        // default list
        $keys = getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options");

        // get columns where "unique" options is true.
        $columns = $this->custom_table
            ->custom_columns()
            ->where('options->unique', "1")
            ->pluck('column_view_name', 'column_name')
            ->toArray();
        // add key name "value.";
        $val_columns = [];
        foreach ($columns as $column_key => $column_value) {
            $val_columns['value.'.$column_key] = $column_value;
        }

        // merge
        $keys = array_merge($keys, $val_columns);

        return $keys;
    }
}

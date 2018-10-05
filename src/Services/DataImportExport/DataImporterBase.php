<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Facades\Admin;
use Validator;
use Carbon\Carbon;

abstract class DataImporterBase
{
    protected $custom_table;
    protected $accept_extension = '';
    public function __construct($custom_table){
        $this->custom_table = $custom_table;
    }


    /**
     * @param $request
     * @return mixed|void error message or success message etc...
     */
    public function import($request)
    {
        // validate request
        $validateRequest = $this->validateRequest($request);
        if($validateRequest !== true){
            return [
                'result' => false,
                //'toastr' => exmtrans('common.message.import_error'),
                'errors' => $validateRequest,
            ];
        }

        // get table data
        $tableData = $this->getDataTable($request);
        //Remove empty data csv
        $data = array_filter($tableData, function($value) { return count(array_filter($value)) > 0 ; });
        list($data_import, $error_data) = $this->checkingData($request->select_action, $data);
        
        // if has error data, return error data
        if(count($error_data) > 0){
            return [
                'result' => false,
                'toastr' => exmtrans('common.message.import_error'),
                'errors' => ['import_error_message' => ['type' => 'input', 'message' => implode("\r\n", $error_data)]],
            ];
        }

        // loop error data
        foreach ($data_import as $index => $row)
        {
            $validate_data = $this->validateData($request,$row);
            if ($validate_data) {
                $data_custom = $this->dataProcessing($row);
                $this->dataImportFlow($request->custom_table_name, $data_custom, $request->select_primary_key);
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
    public function validateRequest($request){
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
     * @param $request
     * @param $data
     * @return bool
     */
    public function validateData($request,$data){
        $validateData = true;
        $custom_columns = $this->custom_table->custom_columns;
        foreach($data as $key => $value){
            if(strpos($key, "value.") !== false){
                $new_key = str_replace('value.', '', $key);
                foreach($custom_columns as $custom_column_data){
                    if ($custom_column_data->column_name == $new_key && $custom_column_data->column_type == 'select' ) {
                        $options = createSelectOptions(array_get(json_decode($custom_column_data->options, true), 'select_item'));
                        if (in_array($value, $options)) {
                            $validateData = true;
                        } else {
                            return false;
                        }

                    }
                }
            }
        }
        return $validateData;
    }

    /**
     * @param $action
     * @param $data
     * @return array
     */
    public function checkingData($action, $data){
        $error_data = array();
        $success_data = array();
        $headers = array();
        foreach($data as $key => $value){
            // column_name row
            if ($key === 0)
            {
                $headers = $value;
            } 
            // column_view_name row
            elseif ($key === 1)
            {
                continue;
            } 
            else {
                $data_custom = array_combine($headers, $value);

                $check = $this->checkingDataItem($key, $data_custom);
                if($check === true){
                    array_push($success_data, $data_custom);
                }
                else {
                    $error_data = array_merge($error_data, $check);
                }
            }
        }
        return [$success_data, $error_data];
    }

    /**
     * @param $action
     * @param $data
     * @return array
     */
    public function checkingDataItem($line_no, $data_custom){
        // create validate rule
        $rules = [
            'id' => 'nullable|regex:/^[0-9]+$/',
            'suuid' => 'nullable|regex:/^[a-z0-9]{20}$/',
            'created_at' => 'nullable|date',
            'updated_at' => 'nullable|date',
            'deleted_at' => 'nullable|date',
        ];
        $validator = Validator::make($data_custom, $rules);
        if ($validator->fails()) {
            // create error message
            $errors = [];
            foreach($validator->errors()->messages() as $message){
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), $line_no, implode(',', $message));
            }
            return $errors;
        }
        return true;
    }

    /**
     * @param $data
     * @return array
     */
    public function dataProcessing($data){
        $data_custom = array();
        $value_arr = array();
        foreach($data as $key => $value){
            if(strpos($key, "value.") !== false){
                $new_key = str_replace('value.', '', $key);
                $value_arr[$new_key] = $value;
            }else{
                $data_custom[$key] = is_nullorempty($value) ? null : $value;  
            }
        }
        $data_custom['value'] = $value_arr;
        return $data_custom;
    }

    /**
     * import data 
     */
    public function dataImportFlow($table_name, $data, $primary_key){
        $modelName = getModelName($table_name);
        // select $model using primary key and value
        $primary_value = array_get($data, $primary_key);
        // if not exists, new instance
        if(is_nullorempty($primary_value)){
            $model = new $modelName;
            $isCreate = true;
        }
        // if exists, firstOrNew
        else{
            $model = $modelName::firstOrNew([$primary_key => $primary_value]);
            $isCreate = !$model->exists;
        }
        if(!isset($model)){return;}

        // loop for data
        foreach($data as $dkey => $dvalue){
            $dvalue = is_nullorempty($dvalue) ? null : $dvalue;
            // if not exists column, continue
            if(!in_array($dkey, ['id', 'suuid', 'parent_id', 'parent_type', 'value', 'created_at', 'updated_at', 'deleted_at'])){
                continue;
            }
            // setvalue function if key is value
            if($dkey == 'value'){
                // loop dvalue
                foreach($dvalue as $dvalueKey => $dvalueValue){
                    $model->setValue($dvalueKey, $dvalueValue);
                }
            }
            // if timestamps
            elseif(in_array($dkey, ['created_at', 'updated_at', 'deleted_at'])){
                // if null, contiune
                if(is_nullorempty($dvalue)){
                    continue;
                }
                // if not create and created_at, continue(because time back)
                if(!$isCreate && $dkey == 'created_at'){
                    continue;
                }
                // set as date
                $model->{$dkey} = Carbon::parse($dvalue);
            }
            // if id or suuid
            elseif(in_array($dkey, ['id', 'suuid'])){
                // if null, contiune
                if(is_nullorempty($dvalue)){
                    continue;
                }
                $model->{$dkey} = $dvalue;
            }
            // else, set 
            else{
                $model->{$dkey} = $dvalue;
            }
        }
        // save model
        $model->save();
    }

    public function importModal(){
        $table_name = $this->custom_table->table_name;
        $import_path = admin_base_path('data/'.$table_name.'/import');
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->disableReset();

        $form->action(admin_base_path('data/'.$table_name.'/import'))
            ->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:csv,xlsx')->setWidth(8, 3)->addElementClass('custom_table_file')
            ->options(['showPreview' => false])
            ->help(exmtrans('custom_value.import.help.custom_table_file'));
            
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options"))
            ->default('id')
            ->setWidth(8, 3)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));

            $form->select('select_action', exmtrans('custom_value.import.error_flow'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_ERROR, "custom_value.import.error_options"))
            ->default('stop')
            ->setWidth(8, 3)
            ->addElementClass('select_action')
            ->help(exmtrans('custom_value.import.help.error_flow'));
    
            $form->textarea('import_error_message', exmtrans('custom_value.import.import_error_message'))
            ->attribute(['readonly' => true])
            ->setWidth(8, 3)
            ->rows(4)
            ->addElementClass('import_error_message')
            ->help(exmtrans('custom_value.import.help.import_error_message'));
    
        $form->hidden('custom_table_name')->default($table_name);
        $form->hidden('custom_table_suuid')->default($this->custom_table->suuid);
        $form->hidden('custom_table_id')->default($this->custom_table->id);

        $modal = view('exment::custom-value.import-modal', ['form' => $form]);

        return $modal;
    }
    
    /**
     * get importer model
     */
    public static function getModel($custom_table, $format = null)
    {
        switch($format){
            case 'excel':
                return new ExcelImporter($custom_table);
            default:
                return new CsvImporter($custom_table);
        }
    }

    /**
     * get uploaded file extension
     */
    public static function getFileExtension($request){
        $file = $request->file('custom_table_file');
        if(!isset($file)){
            return null;
        }
        switch($file->extension()){
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
    abstract protected function getDataTable($request);

}

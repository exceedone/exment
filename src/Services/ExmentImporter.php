<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;
use Validator;
use Carbon\Carbon;

class ExmentImporter
{
    protected $tabel;
    /**
     * @param $request
     * @return mixed|void
     */
    public function import($request)
    {
        $this->custom_table = CustomTable::find($request->custom_table_id);
        $validFileFormat = false;

        if($request->hasfile('custom_table_file')){
            $validFileFormat = $this->validateFormatFile($request);
        }

        if($validFileFormat === false || $request->select_primary_key === null){
            return false;
        }
        else {
            $path = $request->file('custom_table_file')->getRealPath();
            $dataCsv = array_map('str_getcsv', file($path));
            //Remove empty data csv
            $data = array_filter($dataCsv, function($value) { return count(array_filter($value)) > 0 ; });
            $data_import = $this->checkingData($request->select_action, $data);
            if(count($data_import) <= 0){
                return false;
            }
            foreach ($data_import as $index => $row)
            {
                $validate_data = $this->validateData($request,$row);
                if ($validate_data) {
                    $data_custom = $this->dataProcessing($row);
                    $this->dataImportFlow($request->custom_table_name, $data_custom, $request->select_primary_key);

                }
            }
        }
        return $validFileFormat;
    }

    /**
     * @param $request
     * @return bool
     */
    public function validateFormatFile($request){
        $file = $request->file('custom_table_file');
        $validator = Validator::make(
            [
                'file'      => $file,
                'extension' => strtolower($file->getClientOriginalExtension()),
            ],
            [
                'file'          => 'required',
                'extension'      => 'required|in:csv',
            ]
        );
        $validFileFormat = $validator->passes();
        // validate header file
        // if ($validFileFormat) {
        //     $path = $request->file('custom_table_file')->getRealPath();
        //     $data = array_map('str_getcsv', file($path));
        //     $header = $this->getHeader($request->custom_table_suuid);
        //     $result=array_diff($header,$data[0]);
        //     if ($result) {
        //         $validFileFormat =  false;
        //     } else {
        //         $validFileFormat = true;
        //     }

        // }
        return $validFileFormat;
    }

    /**
     * @param $suuid
     * @return array
     */
    // public function getHeader($suuid){
    //     $header = [];
    //     $columnName = \Schema::getColumnListing('exm__'.$suuid);
    //     foreach($columnName as $key => $value){
    //         if((strpos($value, 'id') !== false && strpos($value, 'parent') === false) || strpos($value, 'suuid') !== false){
    //             $header[$key] = $value;
    //         }
    //         if(strpos($value, 'column_') !== false){
    //             $column_suuid = str_replace('column_', '', $value);
    //             array_push($header, 'value.'.$this->getDisplayColumnName($column_suuid));
    //         }
    //     }
    //     array_push($header, 'created_at');
    //     array_push($header, 'updated_at');
    //     return array_dot($header);
    // }

    // /**
    //  * @param $column_suuid
    //  * @return mixed
    //  */
    // public function getDisplayColumnName($column_suuid){
    //     $columnName = DB::table('custom_columns')
    //         ->where('suuid', '=' ,$column_suuid)
    //         ->first();
    //     return $columnName->column_name;
    // }


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
            if ($key === 0)
            {
                $headers = $value;
            } else {
                $data_custom = array_combine($headers, $value);

                if($this->checkingDataItem($data_custom)){
                    array_push($success_data, $data_custom);
                }
                else {
                    array_push($error_data, $data_custom);
                }
            }
        }
        if(count($error_data) > 0 && $action === 'stop'){
            return $success_data = array();
        }
        return $success_data;
    }

    /**
     * @param $action
     * @param $data
     * @return array
     */
    public function checkingDataItem($data_custom){
        // check id
        if(!is_nullorempty(array_get($data_custom, 'id'))){
            $match = preg_match('/[0-9]/', $data_custom['id']);
            if(!$match){
                return false;
            }
        }

        // check suuid
        if(!is_nullorempty(array_get($data_custom, 'suuid'))){
            $match = preg_match('/[a-z0-9]{20}/', $data_custom['suuid']);
            if(!$match){
                return false;
            }
        }

        // check date
        foreach(['created_at', 'updated_at', 'deleted_at'] as $dkey){
            if (!is_nullorempty(array_get($data_custom, $dkey))) {
                try{
                    Carbon::parse(array_get($data_custom, $dkey));
                }catch(Exception $ex){
                    return false;
                }
            }
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
        // create form fields
        $form = new \Encore\Admin\Widgets\Form();
        $form->action(admin_base_path('data/'.$table_name.'/import'))
            ->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:csv')->setWidth(8, 3)->addElementClass('exment_import_file')
            ;
        $form->disablePjax();
            
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options"))
            ->setWidth(8, 3)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));

        $form->select('select_action', exmtrans('custom_value.import.error_flow'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_ERROR, "custom_value.import.error_options"))
            ->setWidth(8, 3)
            ->addElementClass('select_action')
            ->help(exmtrans('custom_value.import.help.error_flow'));
    
        $form->hidden('custom_table_name')->default($table_name);
        $form->hidden('custom_table_suuid')->default($this->custom_table->suuid);
        $form->hidden('custom_table_id')->default($this->custom_table->id);

        $modal = view('exment::custom-value.import-modal', ['form' => $form]);

        // Add script
//         $script = <<<EOT
//         $(document).ready(function(){
//                             $("#data_import_modal [submit]").click(function () {
//                                 var file_name = $('.file-caption-name').attr("title");
//                                 var primary_key = $('#import-form').find('span[id^="select2-select_primary_key"]').attr( "title" );
//                                 var primary_key_placeholder = $('#import-form').find('span[id^="select2-select_primary_key"]').find('span[class^="select2-selection__placeholder"]').text();
//                                 var action = $('#import-form').find('span[id^="select2-select_action"]').attr( "title" );
//                                 var action_placeholder = $('#import-form').find('span[id^="select2-select_action"]').find('span[class^="select2-selection__placeholder"]').text();
//                                 if(file_name === undefined || file_name === "" ){
//                                     $('.file-caption-name').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('.file-caption-name').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if( primary_key === undefined || (primary_key.indexOf(primary_key_placeholder) === -1 && primary_key_placeholder !== "")){
//                                     $('#import-form').find('span[id^="select2-select_primary_key"]').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('#import-form').find('span[id^="select2-select_primary_key"]').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if(action === undefined || (action.indexOf(action_placeholder) === -1 && action_placeholder !== "")){
//                                     $('#import-form').find('span[id^="select2-select_action"]').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('#import-form').find('span[id^="select2-select_action"]').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if(file_name === undefined || primary_key === undefined || (primary_key.indexOf(primary_key_placeholder) === -1 && primary_key_placeholder !== "")
//                                 || action === undefined || (action.indexOf(action_placeholder) === -1 && action_placeholder !== "" || file_name === "")){
//                                     toastr.error("Please fill all red input");
//                                     return false;
//                                 }
//                                 $('.modal-backdrop').remove();
//                             });
//                         });
        // EOT;
//             Admin::script($script);
    
        return $modal;
    }
}

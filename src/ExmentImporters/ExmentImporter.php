<?php

namespace Exceedone\Exment\ExmentImporters;

use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;
use Validator;


class ExmentImporter extends ExmentAbstractImporter
{

    /**
     * @param $request
     * @return mixed|void
     */
    public function import($request)
    {
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
        if ($validFileFormat) {
            $path = $request->file('custom_table_file')->getRealPath();
            $data = array_map('str_getcsv', file($path));
            $header = $this->getHeader($request->custom_table_suuid);
            $result=array_diff($header,$data[0]);
            if ($result) {
                $validFileFormat =  false;
            } else {
                $validFileFormat = true;
            }

        }
        return $validFileFormat;
    }

    /**
     * @param $suuid
     * @return array
     */
    public function getHeader($suuid){
        $header = [];
        $columnName = \Schema::getColumnListing('exm__'.$suuid);
        foreach($columnName as $key => $value){
            if((strpos($value, 'id') !== false && strpos($value, 'parent') === false) || strpos($value, 'suuid') !== false){
                $header[$key] = $value;
            }
            if(strpos($value, 'column_') !== false){
                $column_suuid = str_replace('column_', '', $value);
                array_push($header, 'value.'.$this->getDisplayColumnName($column_suuid));
            }
        }
        array_push($header, 'created_at');
        array_push($header, 'updated_at');
        return array_dot($header);
    }


    /**
     * @param $column_suuid
     * @return mixed
     */
    public function getDisplayColumnName($column_suuid){
        $columnName = DB::table('custom_columns')
            ->where('suuid', '=' ,$column_suuid)
            ->first();
        return $columnName->column_name;
    }


    /**
     * @param $request
     * @param $data
     * @return bool
     */
    public function validateData($request,$data){
        $validateData = true;
        $custom_columns = $this->getCustomColumn($request->custom_table_id);
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
                if(($data_custom['suuid'] === "" || (preg_match('/[a-z0-9]/', $data_custom['suuid']) > 0 && strlen($data_custom['suuid']) === 20)) &&
                    ((preg_match('/[0-9]/', $data_custom['id']) > 0 && $data_custom['id'] !== "" && $data_custom['id'] > 0 && strlen($data_custom['id']) <= 10) || $data_custom['id'] === "") &&
                    $this->checkDateTime($data_custom['created_at'], $data_custom['updated_at'])){
                    if($data_custom['suuid'] === "" ){
                        $data_custom['suuid'] = mb_substr(make_uuid(), 0, 20);
                    }
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

    public function checkDateTime($date_created, $date_updated){
        if($date_created === "" && $date_updated === ""){
            return true;
        }
        if(($date_created !== "" && $date_created === date('Y-n-d H:i:s', strtotime($date_created)))
        && (($date_updated !== "" && $date_updated === date('Y-n-d H:i:s', strtotime($date_updated))))
        && ($date_updated >= $date_created)){
            return true;
        }
        if($date_created !== "" && $date_created === date('Y-n-d H:i:s', strtotime($date_created))
        && $date_updated === "" ){
            return true;
        }
        if($date_updated !== "" && $date_updated === date('Y-n-d H:i:s', strtotime($date_updated))
            && $date_created === "" ){
            return true;
        }

        return false;
    }

    /**
     * @param $table_id
     * @return mixed
     */
    public function getCustomColumn($table_id){
        $custom_columns = CustomTable::find($table_id)->custom_columns;
        return $custom_columns;
    }

    /**
     * @param $data
     * @return array
     */
    public function dataProcessing($data){
        $data_custom = array();
        $data_custom['id'] = $data['id'];
        $data_custom['suuid'] = ($data['suuid'] !== "" && $data['suuid'] !== "null") ? $data['suuid'] : mb_substr(make_uuid(), 0, 20);
        $value_arr = array();
        foreach($data as $key => $value){
            if(strpos($key, "value.") !== false){
                $new_key = str_replace('value.', '', $key);
                $value_arr[$new_key] = $value;
            }
        }
        //$data_custom['value'] = json_encode($value_arr);
        $data_custom['value'] = $value_arr;
        if($data['created_at'] === "" && $data['updated_at'] === ""){
            $data_custom['created_at'] = $data_custom['updated_at'] = date("Y-n-d H:i:s");
        }
        else if($data['created_at'] === "" && $data['updated_at'] !== ""){
            $data_custom['created_at'] = $data_custom['updated_at'] = $data['updated_at'];
        }
        else if($data['created_at'] !== "" && $data['updated_at'] === ""){
            $data_custom['created_at'] = $data['created_at'];
            $data_custom['updated_at'] = date("Y-n-d H:i:s");
        }
        else {
            $data_custom['created_at'] = $data_custom['updated_at'] = date("Y-n-d H:i:s");
        }
        return $data_custom;
    }

    public function dataImportFlow($table_name, $data, $primary_key){
        $modelName = getModelName($table_name);
        $model = new $modelName();
        if((strtolower($primary_key) === 'id' || strtolower($primary_key) === 'suuid') && $data[$primary_key] !== ""){
            $data_import = getModelName($table_name)::where($primary_key, '=', $data[$primary_key])->first();
            if($data_import === null){
                $model->id = $data['id'];
                $model->suuid = $data['suuid'];
                $model->value = $data['value'];
                $model->created_at = $data['created_at'];
                $model->updated_at = $data['updated_at'];
                $model->save();
            } else {
                $model->value = $data['value'];
                $model->created_at = $data['created_at'];
                $model->updated_at = $data['updated_at'];
                if($primary_key === 'id'){
                    getModelName($table_name)::where($primary_key, '=', $data[$primary_key])->update(['suuid'=> $data['suuid'],
                        'value'=>$data['value'], 'created_at'=>$data['created_at'], 'updated_at'=>$data['updated_at']]);
                }
                else {
                    getModelName($table_name)::where($primary_key, '=', $data[$primary_key])->update(['id'=>$data['id'],
                        'value'=> $data['value'], 'created_at'=>$data['created_at'], 'updated_at'=>$data['updated_at']]);
                }
            }
        }
        else if(strtolower($primary_key) === 'id' && $data[$primary_key] === "" && $data['suuid'] !== ""){
            $data_import = getModelName($table_name)::where('suuid', '=', $data['suuid'])->first();
            if($data_import === null){
                $model->id = $data['id'];
                $model->suuid = $data['suuid'];
                $model->value = $data['value'];
                $model->created_at = $data['created_at'];
                $model->updated_at = $data['updated_at'];
                $model->save();
            }else {
                $model->value = $data['value'];
                $model->created_at = $data['created_at'];
                $model->updated_at = $data['updated_at'];
                getModelName($table_name)::where('suuid', '=', $data['suuid'])->update(['id'=>$data['id'],
                    'value'=> $data['value'], 'created_at'=>$data['created_at'], 'updated_at'=>$data['updated_at']]);
            }
        }
        else {
            $model->id = $data['id'];
            $model->suuid = $data['suuid'];
            $model->value = $data['value'];
            $model->created_at = $data['created_at'];
            $model->updated_at = $data['updated_at'];
            $model->save();
        }

    }
}

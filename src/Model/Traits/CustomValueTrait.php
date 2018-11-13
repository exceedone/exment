<?php

namespace Exceedone\Exment\Model\Traits;
use Encore\Admin\Facades\Admin;
use Carbon\Carbon;

trait CustomValueTrait
{       
    // re-set field data --------------------------------------------------
    // if user update form and save, but other field remove if not conatins form field, so re-set field before update
    protected static function regetOriginalData($model){
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value. 
        $value = $model->value;
        $original = json_decode($model->getOriginal('value'), true);
        // get  columns
        $file_columns = $model->getCustomTable()
            ->custom_columns
            ->pluck('column_name')
            ->toArray();

        // loop columns
        $update_flg = false;
        foreach ($file_columns as $file_column) {

            // if not set, set from original
            if(!array_key_value_exists($file_column, $value)) {
                // if column has $remove_file_columns, continue.
                // property "$remove_file_columns" uses user wants to delete file
                if(in_array($file_column, $model->remove_file_columns())){
                    continue;
                }

                if(array_key_value_exists($file_column, $original)){
                    $value[$file_column] = array_get($original, $file_column);
                    $update_flg = true;
                }
            }
        }
        // if update
        if ($update_flg) {
            $model->setAttribute('value', $value);
        }
    }

    // set auto number --------------------------------------------------
    protected static function setAutoNumber($model){
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value. 
        $value = $model->value;
        $id = $model->id;
        // get image and file columns
        $columns = $model->getCustomTable()
            ->custom_columns
            ->all();

        $update_flg = false;
        // loop columns
        foreach ($columns as $custom_column) {
            // custom column
            $column_name = array_get($custom_column, 'column_name');
            switch (array_get($custom_column, 'column_type')) {
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
                        $auto_number = static::createAutoNumberFormat($model, $id, $options);
                    }
                    // if auto_number_type is random25, set value
                    elseif (array_get($options, 'auto_number_type') == 'random25') {
                        $auto_number = make_licensecode();
                    }
                    // if auto_number_type is UUID, set value
                    elseif (array_get($options, 'auto_number_type') == 'random32') {
                        $auto_number = make_uuid();
                    }

                    if (isset($auto_number)) {
                        $model->setValue($column_name, $auto_number);
                        $update_flg = true;
                    }
                    break;
            }
        }
        // if update
        if ($update_flg) {
            $model->save();
        }
    }

    /**
     * Create Auto Number value using format.
     */
    protected static function createAutoNumberFormat($model, $id, $options){
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
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable($related_type){
        if($related_type == Define::SYSTEM_TABLE_NAME_USER){
            $query = $this
            ->value_authoritable_users()
            ->where('related_id', Admin::user()->base_user_id);
        }else if($related_type == Define::SYSTEM_TABLE_NAME_ORGANIZATION){
            $query = $this
            ->value_authoritable_organizations()
            ->whereIn('related_id', Admin::user()->getOrganizationIds());
        }

        return $query->get();
    }

    public function getValue($column = null, $label = false){
        return getValue($this, $column, $label);
    }
    public function setValue($key, $val = null){
        if(!isset($key)){return;}
        // if key is array, loop key value
        if(is_array($key)){
            foreach($key as $k => $v){
                $this->setValue($k, $v);
            }
            return $this;
        }
        $value = $this->value;
        if(is_null($value)){$value = [];}
        $value[$key] = $val;
        $this->value = $value;

        return $this;
    }
    
    /**
     * get target custom_value's link url
     */
    public function getUrl($tag = false){
        $url = admin_url(url_join('data', $this->getCustomTable()->table_name, $this->id));
        if(!$tag){
            return $url;
        }
        $url .= '?modal=1';
        $label = $this->getValue(null, true);
        return "<a href='javascript:void(0);' data-widgetmodal_url='$url'>$label</a>";
    }


}

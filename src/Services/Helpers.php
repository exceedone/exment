<?php
use Encore\Admin\Widgets\Table as WidgetTable;
use \Exceedone\Exment\Services\ClassBuilder;
use \Exceedone\Exment\Model\Define;
use \Exceedone\Exment\Model\System;
use \Exceedone\Exment\Model\File;
use \Exceedone\Exment\Model\Authority;
use \Exceedone\Exment\Model\CustomTable;
use \Exceedone\Exment\Model\CustomColumn;
use \Exceedone\Exment\Model\CustomRelation;
use \Exceedone\Exment\Model\CustomValue;
use \Exceedone\Exment\Model\CustomViewColumn;
use \Exceedone\Exment\Model\CustomView;
use \Exceedone\Exment\Model\ModelBase;
use \Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Webpatser\Uuid\Uuid;

if (!function_exists('exmtrans')) {
    function exmtrans($key)
    {
        return trans("exment::exment.$key");
    }
}


if (!function_exists('is_nullorempty')) {
    function is_nullorempty($obj)
    {
        if(is_null($obj)){return true;}
        if(is_string($obj) && strlen($obj) == 0){return true;}
        return false;
    }
}

if (!function_exists('parseIntN')) {
    /**
     * parseInt
     * if cannot parse, return null.
     * TODO:common lib
     * @param mixed $str
     * @return \double|integer|null
     */
    function parseIntN($str)
    {
        $str = str_replace(',', '', $str);

        if (is_numeric($str)) {
            return $str;
        }
        return null;
    }
}


// File, path  --------------------------------------------------
if (!function_exists('namespace_join')) {
    /**
     * Join NameSpace.
     */
    function namespace_join(...$pass_array)
    {
        return join_paths("\\", $pass_array);
    }
}

if (!function_exists('path_join')) {
    /**
     * Join FilePath.
     */
    function path_join(...$pass_array)
    {
        return join_paths(DIRECTORY_SEPARATOR, $pass_array);
    }
}

if (!function_exists('url_join')) {
    /**
     * Join FilePath.
     */
    function url_join(...$pass_array)
    {
        return join_paths("/", $pass_array);
    }
}


if (!function_exists('join_paths')) {
    /**
     * Join path using trim_str.
     */
    function join_paths($trim_str, $pass_array)
    {
        $ret_pass   =   "";

        foreach ($pass_array as $value) {
            if ($ret_pass == "") {
                $ret_pass   =   $value;
            }else {
                $ret_pass   =   rtrim($ret_pass,$trim_str);
                $value      =   ltrim($value,$trim_str);
                $ret_pass   =   $ret_pass.$trim_str.$value;
            }
        }
        return $ret_pass;
    }
}


if (!function_exists('getFullpath')) {
    function getFullpath($filename, $disk)
    {
        return Storage::disk($disk)->getDriver()->getAdapter()->applyPathPrefix($filename);
    }
}


if (!function_exists('getPluginNamespace')) {
    function getPluginNamespace(...$pass_array)
    {
        $basename = "\\App\\Plugins";
        if(isset($pass_array) && count($pass_array) > 0){
            array_unshift($pass_array, $basename);
            $basename = namespace_join($pass_array);
        }
        return $basename;
    }
}





// array --------------------------------------------------
if (!function_exists('array_keys_exists')) {
    /**
     * array_keys_exists
     * $keys contains $array, return true.
     * @param array $keys
     * @param array $array
     * @return bool
     */
    function array_keys_exists($keys, $array)
    {
        if (is_null($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('array_key_value_exists')) {
    /**
     * whether has array_key and array_get
     * @param mixed $str
     * @return bool
     */
    function array_key_value_exists($key, $array)
    {
        if (!array_key_exists($key, $array)) {
            return false;
        }
        return !empty(array_get($array, $key));
    }
}

if (!function_exists('array_has_value')) {
    /**
     * whether has array_key and array_get
     * @return bool
     */
    function array_has_value($array, $key)
    {
        if(is_null($array)){return false;}
        if (!array_has($array, $key)) {
            return false;
        }
        if(is_string($key)){
            $key = [$key];
        }
        // get each value and check isset
        foreach($key as $k){
            $val = array_get($array, $k);
            if(!isset($val)){
                return false;
            }
        }
        return true;
    }
}

function is_json($string){
    return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
}
 

// string --------------------------------------------------
if (!function_exists('make_password')) {
    function make_password($length = 16)
    {
        static $chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!$#%_-";
        $str = '';
        for ($i = 0; $i < $length; ++$i) {
            $str .= $chars[mt_rand(0, strlen($chars) -1)];
        }
        return $str;
    }
}

if (!function_exists('make_randomstr')) {
    function make_randomstr($length)
    {
        static $chars = "abcdefghjkmnpqrstuvwxyz23456789";
        $str = '';
        for ($i = 0; $i < $length; ++$i) {
            $str .= $chars[mt_rand(0, strlen($chars) -1)];
        }
        return $str;
    }
}

if (!function_exists('make_uuid')) {
    function make_uuid()
    {
        return Uuid::generate()->string;
    }
}

if (!function_exists('short_uuid')) {
    /**
     * Get the short uuid (length 20)
     * @return string
     */
    function short_uuid()
    {
        return mb_substr(md5(uniqid()), 0, 20);
    }
}

if (!function_exists('make_licensecode')) {
    function make_licensecode()
    {
        return make_randomstr(5).'-'.make_randomstr(5).'-'.make_randomstr(5).'-'.make_randomstr(5).'-'.make_randomstr(5);
    }
}

if (!function_exists('pascalize')) {
    function pascalize($string)
    {
        $string = strtolower($string);
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        $string = str_replace(' ', '', $string);
        return $string;
    }
}

if (!function_exists('get_password_rule')) {
    /**
     * get_password_rule(for validation)
     * @return string
     */
    function get_password_rule($required = true)
    {
        $validates = [];
        if ($required) {
            array_push($validates, 'required');
        }else{
            array_push($validates, 'nullable');
        }
        array_push($validates, 'confirmed');
        array_push($validates, 'min:'.(!is_null(config('exment.password_rule.min')) ? config('exment.password_rule.min') : '8'));
        array_push($validates, 'max:'.(!is_null(config('exment.password_rule.max')) ? config('exment.password_rule.max') : '32'));
        
        if(!is_null(config('exment.password_rule.rule'))){
            array_push($validates, 'regex:/'.config('exment.password_rule.rule').'/');
        }

        return implode("|", $validates);
    }
}

// Laravel, laravel-admin --------------------------------------------------
if (!function_exists('getModelName')) {
    /**
     * Get custom_value's model fullpath.
     * this function contains flow creating eloquent class dynamically.
     * @param string|CustomTable|CustomValue $obj
     * @return string
     */
    function getModelName($obj, $get_name_only = false)
    {
        if (is_numeric($obj)) {
            // Get suuid.
            // using DB query builder (because this function may be called createCustomTableExt. this function is trait CustomTable
            //$table = CustomTable::find($obj);
            $suuid = DB::table('custom_tables')->where('id', $obj)->first()->suuid ?? null;
        }
        else if (is_string($obj)) {
            // get by table_name
            // $table = CustomTable::findByName($obj);
            $suuid = DB::table('custom_tables')->where('table_name', $obj)->first()->suuid ?? null;
        } else if($obj instanceof CustomValue) {
            $table = $obj->getCustomTable();
            $suuid = $table->suuid;
        } else {
            $table = $obj;
            $suuid = $table->suuid;
        }

        $namespace = "App\\CustomModel";
        $className = "Class_{$suuid}";
        $fillpath = "{$namespace}\\{$className}";
        // if the model doesn't defined, and $get_name_only is false
        // create class dynamically.
        if (!$get_name_only && !class_exists($fillpath)) {
            // get table. this block isn't called by createCustomTableExt
            $table = CustomTable::findBySuuid($suuid);
            createTable($table);    
            ClassBuilder::createCustomValue($namespace, $className, $fillpath, $table, $obj);
        }

        return "\\".$fillpath;
    }
}
if (!function_exists('getCustomTableExt')) {
    /**
     * For use function in "CustomTable"、create CustomTableExt class
     * @param string|CustomTable $obj
     * @return string
     */
    function getCustomTableExt()
    {
        $namespace = "Exceedone\\Exment\\Model";
        $className = "CustomTableExt";
        $fillpath = "{$namespace}\\{$className}";
        // if the model doesn't defined
        if (!class_exists($fillpath)) {
            ClassBuilder::createCustomTableExt($namespace, $className, $fillpath);
        }

        return "\\".$fillpath;
    }
}

if (!function_exists('getDBTableName')) {
    /**
     * Get database table name.
     * @param string|CustomTable|array $obj
     * @return string
     */
    function getDBTableName($obj)
    {
        $obj = CustomTable::getEloquent($obj);
        return 'exm__'.array_get($obj, 'suuid');
    }
}

if (!function_exists('getEndpointName')) {
    /**
     * get endpoint name.
     * @param mixed $obj
     * @return string
     */
    function getEndpointName($obj)
    {
        // if model
        if ($obj instanceof ModelBase) {
            $ref = new \ReflectionClass(get_class($obj));
            return snake_case($ref->getShortName());
        }

        return null;
    }
}

if (!function_exists('getColumnName')) {
    /**
     * Get column name. This function uses only search-enabled column.
     * @param CustomColumn|array $obj
     * @return string
     */
    function getColumnName($obj)
    {
        if ($obj instanceof CustomColumn) {
            $obj = $obj->toArray();
        }
        if ($obj instanceof stdClass) {
            $obj = (array)$obj;
        }
        return 'column_'.array_get($obj, 'suuid');
    }
}

if (!function_exists('getColumnNameByTable')) {
    /**
     * Get column name using table model. This function uses only search-enabled column.
     * @param string|CustomTable|array $obj
     * @return string
     */
    function getColumnNameByTable($obj, $column_name)
    {
        $obj = CustomTable::getEloquent($obj);
        $column = $obj->custom_columns()->where('column_name', $column_name)->first();
        return getColumnName($column);
    }
}

if (!function_exists('getLabelColumn')) {
    /**
     * Get label column object for target table.
     * @param CustomTable|array $obj
     * @return string
     */
    function getLabelColumn($obj, $isonly_label = false)
    {
        $obj = CustomTable::getEloquent($obj);
        $column = $obj->custom_columns()->whereIn('options->use_label_flg', [1, "1"])->first();
        if (!isset($column)) {
            $column = $obj->custom_columns()->first();
        }

        if($isonly_label){
            return $column->column_view_name;
        }else{
            return $column;
        }
    }
}

if (!function_exists('getRelationName')) {
    /**
     * Get relation name.
     * @param CustomRelation $obj
     * @return string
     */
    function getRelationName($obj)
    {
        return getRelationNamebyObjs($obj->parent_custom_table, $obj->child_custom_table);
    }
}

if (!function_exists('getRelationNamebyObjs')) {
    /**
     * Get relation name using parent and child table.
     * @param $parent
     * @param $child
     * @return string
     */
    function getRelationNamebyObjs($parent, $child)
    {
        $parent_suuid = CustomTable::getEloquent($parent)->suuid;
        $child_suuid = CustomTable::getEloquent($child)->suuid;
        return "pivot_{$parent_suuid}_{$child_suuid}";
    }
}

if (!function_exists('getAuthorityName')) {
    /**
     * Get atuhority name.
     * @param Authority $obj
     * @return string
     */
    function getAuthorityName($obj, $related_type)
    {
        return "authority_{$obj->suuid}_{$related_type}";
    }
}

if (!function_exists('authorityLoop')) {
    /**
     * @return string
     */
    function authorityLoop($related_type, $callback)
    {
        if (!Schema::hasTable(System::getTableName()) || !Schema::hasTable(Authority::getTableName())) {
            return;
        }
        if(!System::authority_available()){
            return;
        }
        
        // get Authority setting
        $authorities = Authority::where('authority_type', $related_type)->get();
        foreach ($authorities as $authority) {
            $related_types = [Define::SYSTEM_TABLE_NAME_USER];
            // if use organization, add
            if(System::organization_available()){
                $related_types[] = Define::SYSTEM_TABLE_NAME_ORGANIZATION;
            }
            foreach ($related_types as $related_type) {
                $callback($authority, $related_type);
            }
        }
    }
}

if (!function_exists('getValue')) {
    /**
     * Get custom value
     * @param $custom_value
     * @param string|array|CustomColumn $column
     * @param bool $isonly_label if column_type is select_table or select_valtext, only get label 
     * @return string
     */
    function getValue($custom_value, $column = null, $isonly_label = false)
    {
        if(is_null($custom_value)){return null;}
        $custom_table = $custom_value->getCustomTable();

        // get value
        $value = $custom_value->value;
        if (is_null($value)) {
            return null;
        }
        
        return getValueUseTable($custom_table, $value, $column, $isonly_label);
    }
}

if (!function_exists('getValueUseTable')) {
    /**
     * Get Custom Value
     * @param $value
     * @param string|array|CustomColumn $column
     * @param mixin $label if column_type is select_table or select_valtext, only get label 
     * @return string
     */
    function getValueUseTable($custom_table, $value, $column = null, $label = false)
    {
        if (is_null($value)) {
            return null;
        }

        if(is_null($column)){
            $column = getLabelColumn($custom_table);
        }
        
        // get custom column as array
        if (is_string($column)) {
            $column_first = CustomColumn
                ::where('column_name', $column)
                ->where('custom_table_id', $custom_table->id)
                ->first();
                if(is_null($column_first)){return null;}
                $column_array = $column_first->toArray() ?? null;
        } elseif ($column instanceof CustomValue) {
            $column_array = $column->toArray();
        } else {
            $column_array = $column;
        }

        if(is_null($column_array)){return null;}

        if(is_array($value)){
            $key = array_get($column_array, 'column_name');
            $val = array_get($value, $key);
        }else{
            $val = $value;
        }

        if (is_null($val)) {
            return null;
        }
        $column_type = array_get($column_array, 'column_type');

        // get value as select
        // get value as select_valtext
        if (in_array($column_type, ['select', 'select_valtext'])) {
            $array_get_key = $column_type == 'select' ? 'options.select_item' : 'options.select_item_valtext';
            $select_item = array_get($column_array, $array_get_key);
            $options = createSelectOptions($select_item, $column_type == 'select_valtext');
            if (!array_key_exists($val, $options)) {
                return null;
            }
 
            return array_get($options, $val);
        }

        // get value as select_table
        else if (in_array($column_type, ['select_table', 'user', 'organization'])) {
            // get target table
            $target_table_key = null;
            if ($column_type == 'select_table') {
                $target_table_key = array_get($column_array, 'options.select_target_table');
            }else if(in_array($column_type, [Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])){
                $target_table_key = $column_type;
            }
            $target_table = CustomTable::getEloquent($target_table_key);

            $model = getModelName(array_get($target_table, 'table_name'))::find($val);
            if (is_null($model)) {
                return null;
            }
            if ($label === false) {
                return $model;
            }

            // get label column
            // if label is true, get label column
            if($label === true){
                $column = getLabelColumn($target_table);
            }
            // if label is selecting column name, get target label
            else if(is_string($label)){
                $column = CustomColumn::where('custom_table_id', $target_table['id'])->where('column_name', $label)->first();
            }
            if (is_null($column)) {
               return null; 
            }

            // if $model is array multiple, return 
            if(!($model instanceof \Illuminate\Database\Eloquent\Collection)){
                $model = [$model];
            }
            $labels = [];
            foreach($model as $m){
                $labels[] = array_get($m->value, array_get($column->toArray(), 'column_name'));
            }
            return implode(exmtrans('common.separate_word'), $labels);
        }
        else if(in_array($column_type, ['file', 'image'])){
            // Whether multiple file.
            $multiple_enabled = boolval(array_get($column_array, 'options.multiple_enabled'));

            if($multiple_enabled){
                // todo:return multiple files;
                
            }else{
                $file = File::getFile($val);
                return $file;
            }
        }
        else{
            // add comma
            if(boolval(array_get($column_array, 'options.number_format'))){
                $val = number_format($val);
            }
            return $val;
        }
    }
}

if (!function_exists('getParentValue')) {
    /**
     * get parent value
     */
    function getParentValue($custom_value, $isonly_label = false)
    {
        $model = getModelName($custom_value->parent_type)::find($custom_value->parent_id);
        
        if(!$isonly_label){
            return $model;
        }

        if(is_null($model)){
            return null;
        }
        
        // get label column
        $column = getLabelColumn($target_table);
        if (is_null($column)) {
            return null;
        }
        return array_get($model->value, array_get($column->toArray(), 'column_name'));
    }
}

if (!function_exists('getChildrenValues')) {
    /**
     * Get Custom children Value
     */
    function getChildrenValues($custom_value, $relation_table)
    {
        if (is_null($custom_value)) {
            return null;
        }
        $parent_table = $custom_value->getCustomTable();

        // get custom column as array
        $child_table = CustomTable::getEloquent($relation_table);
        $pivot_table_name = getRelationNameByObjs($parent_table, $child_table);

        // get relation item list
        return $custom_value->{$pivot_table_name};
    }
}


if (!function_exists('getSearchEnabledColumns')) {
    /**
     * Get search-enabled columns array.
     * @param mixed $table_name
     * @param mixed $column_name
     * @return array search-enabled columns array
     */
    function getSearchEnabledColumns($table_name)
    {
        $table = CustomTable::findByName($table_name, true)->toArray();
        $column_arrays = [];
        // loop for custom_columns.
        foreach ($table['custom_columns'] as $custom_column) {
            // if custom_column is search_enabled column, add $column_arrays.
            if (boolval(array_get($custom_column['options'], 'search_enabled'))) {
                array_push($column_arrays, $custom_column);
            }
        }
        return $column_arrays;
    }
}

if (!function_exists('createTable')) {
    /**
     * Create Table in Database.
     *
     * @param string|CustomTable $obj
     * @return void
     */
    function createTable($obj)
    {
        $table_name = getDBTableName($obj);
        // if not null
        if(!isset($table_name)){
            throw new Exception('table name is not found. please tell system administrator.');
        }

        // check already execute 
        $key = getRequestSession('create_table.'.$table_name);
        if(boolval($key)){return;}

        // CREATE TABLE from custom value table.
        $db = DB::connection();
        $db->statement("CREATE TABLE IF NOT EXISTS ".$table_name." LIKE custom_values");
        
        setRequestSession($key, 1);
    }
}


if (!function_exists('alterColumn')) {
    /**
     * Alter table column
     * For add table virtual column
     * @param mixed $table_name
     * @param mixed $column_name
     * @param bool $forceDropIndex drop index. calling when remove column.
     */
    function alterColumn($table_name, $column_name, $forceDropIndex = false)
    {
        // check already execute 
        $key = 'global.alter_column.'.$table_name.'_'.$column_name;
        if(boolval(config($key))){return;}

        // Create index --------------------------------------------------
        $table = CustomTable::where('table_name', $table_name)->first();
        $column = $table->custom_columns()->where('column_name', $column_name)->first();

        //DB table name
        $db_table_name = getDBTableName($table);
        $db_column_name = getColumnName($column);

        // Create table
        createTable($table);

        // get whether search_enabled column
        $search_enabled = array_get($column, 'options.search_enabled');
        
        // create INFORMATION_SCHEMA config
        $mysql_config = config('database.connections.mysql');
        $mysql_config['database'] = 'INFORMATION_SCHEMA';
        Config::set('database.connections.mysql_information', $mysql_config);
        // check table column field exists.
        $exists = DB::connection('mysql_information')->table('COLUMNS')
                ->where('table_name', $db_table_name)
                ->where('column_name', $db_column_name)
                ->where('table_schema', DB::getDatabaseName())
                ->first();

        $index_name = "index_$db_column_name";
        //  if search_enabled = false, and exists, then drop index
        // if column exists and (search_enabled = false or forceDropIndex)
        if($exists && ($forceDropIndex || (!boolval($search_enabled)))){
            DB::beginTransaction();
            try {
                // ALTER TABLE
                DB::statement("ALTER TABLE $db_table_name DROP INDEX $index_name;");
                DB::statement("ALTER TABLE $db_table_name DROP COLUMN $db_column_name;");
                DB::commit();
            } catch (Exception $exception) {
                DB::rollback();
                throw $exception;
            }
        }
        // if search_enabled = true, not exists, then create index
        elseif($search_enabled && !$exists){
            DB::beginTransaction();
            try {
                // ALTER TABLE
                DB::statement("ALTER TABLE $db_table_name ADD $db_column_name nvarchar(768) GENERATED ALWAYS AS (json_unquote(json_extract(`value`,'$.$column_name'))) VIRTUAL;");
                DB::statement("ALTER TABLE $db_table_name ADD index $index_name($db_column_name)");
    
                DB::commit();
            } catch (Exception $exception) {
                DB::rollback();
                throw $exception;
            }
        }
        config([$key => 1]);
    }
}

if (!function_exists('createDefaultView')) {
    /**
     * Create DefaultView
     *
     * @param CustomTable $obj
     * @return void
     */
    function createDefaultView($obj)
    {
        $view = new CustomView;
        $view->custom_table_id = $obj->id;
        $view->view_view_name = exmtrans('custom_view.default_view_name');
        $view->saveOrFail();
        return $view;
    }
}

if (!function_exists('createDefaultViewColumns')) {
    /**
     * Create DefaultView
     *
     * @param CustomView $custom_view
     * @return void
     */
    function createDefaultViewColumns($custom_view)
    {
        $view_columns = [];
        // set default view_column
        foreach(Define::VIEW_COLUMN_SYSTEM_OPTIONS as $view_column_system){
            // if not default, continue
            if(!boolval(array_get($view_column_system, 'default'))){
                continue;
            }
            $view_column = new CustomViewColumn;
            $view_column->custom_view_id = $custom_view->id;
            $view_column->view_column_target = array_get($view_column_system, 'name');
            $view_column->order = array_get($view_column_system, 'order');
            array_push($view_columns, $view_column);
        }
        $custom_view->custom_view_columns()->saveMany($view_columns);
        return $view_columns;
    }
}

if (!function_exists('getGridTable')) {
    /**
     * Get Grid table using datalist and view
     * 
     */
    function getGridTable($datalist, CustomTable $custom_table, CustomView $custom_view)
    {
        // get custom view columns
        $custom_view_columns = $custom_view->custom_view_columns;
        
        // create headers
        $headers = [];
        foreach($custom_view_columns as $custom_view_column){
            // get column --------------------------------------------------
            // if number, get custom column
            if(is_numeric($custom_view_column->view_column_target)){
                $custom_column = $custom_view_column->custom_column;
                if(isset($custom_column)){
                    $headers[] = $custom_column->column_view_name;
                }    
            }else{
                // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
                $name = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function($value) use($custom_view_column){
                    return array_get($value, 'name') == array_get($custom_view_column, 'view_column_target');
                })['name'] ?? null;
                // add headers transaction 
                $headers[] = exmtrans('custom_column.system_columns.'.$name);
            }
        }
        $headers[] = trans('admin.action');

        // get table bodies
        $bodies = [];
        if(isset($datalist)){
            foreach($datalist as $data){
                $body_items = [];
                foreach($custom_view_columns as $custom_view_column){
                    // get column --------------------------------------------------
                    // if number, get custom column
                    if(is_numeric($custom_view_column->view_column_target)){
                        $custom_column = $custom_view_column->custom_column;
                        if(isset($custom_column)){
                            $body_items[] = getValue($data, $custom_column, true);
                        }
                    }else{
                        // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
                        $name = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function($value) use($custom_view_column){
                            return array_get($value, 'name') == array_get($custom_view_column, 'view_column_target');
                        })['name'] ?? null;
                        if(isset($name)){
                            $body_items[] = array_get($data, $name);
                        }
                    }
                }

                ///// add show and edit link
                // using authority
                $link = '<a href="'.admin_base_path(url_join('data', array_get($custom_table, 'table_name'), array_get($data, 'id'))).'" style="margin-right:3px;"><i class="fa fa-eye"></i></a>';
                if(Admin::user()->hasPermissionEditData($data->id, $custom_table->table_name)){
                    $link .= '<a href="'.admin_base_path(url_join('data', array_get($custom_table, 'table_name'), array_get($data, 'id'), 'edit')).'"><i class="fa fa-edit"></i></a>';
                }
                $body_items[] = $link;

                // add items to body
                $bodies[] = $body_items;
            }
        }

        //return Widget Table
        return new WidgetTable($headers, $bodies);
    }
}

if (!function_exists('getEndpointTable')) {
    /**
     * Get table object using endpoint name.
     */
    function getEndpointTable($endpoint = null)
    {
        if(!isset($endpoint)){
            $endpoint = url()->current();
        }
        $urls = array_reverse(explode("/", $endpoint));
        foreach ($urls as $url) {
            if (!isset($url)) {
                continue;
            }
            if (mb_substr($url, 0, 1) === "?") {
                continue;
            }
            if (in_array($url, ['index', 'create', 'view', 'show', 'edit'])) {
                continue;
            }

            // joint table
            $table = CustomTable::findByName($url);
            if (isset($table)) {
                return $table;
            }
        }

        return null;
    }
}

if (!function_exists('getTransArray')) {
    /**
     * Create Associative array translated
     */
    function getTransArray($array, $base_key, $isExment = true)
    {
        $associative_array = [];
        foreach ($array as $key) {
            $associative_array[$key] = $isExment ? exmtrans("$base_key.$key") : trans("$base_key.$key");
        }
        return $associative_array;
    }
}

if (!function_exists('getTransArrayValue')) {
    /**
     * Create Associative array translated
     */
    function getTransArrayValue($array, $base_key, $isExment = true)
    {
        $associative_array = [];
        foreach ($array as $key => $value) {
            $associative_array[$key] = $isExment ? exmtrans("$base_key.$value") : trans("$base_key.$value");
        }
        return $associative_array;
    }
}


// laravel-admin --------------------------------------------------
if (!function_exists('isGetOptions')) {
    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * @param array|CustomTable $table
     * @param $selected_value
     */
    function isGetOptions($table)
    {
        // get count table.
        $count = getModelName($table)::count();
        // when count > 0, create option only value.
        return $count <= 100;
    }
}

if (!function_exists('getOptions')) {
    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * @param array|CustomTable $table
     * @param $selected_value
     */
    function getOptions($table, $selected_value)
    {
        $labelcolumn = getLabelColumn($table)->column_name;
        // get count table.
        $count = getModelName($table)::count();
        // when count > 0, create option only value.
        if($count > 100){
            $item = getModelName($table)::find($selected_value);

            if ($item) {
                // check whether $item is multiple value.
                if($item instanceof Collection){
                    $ret = [];
                    foreach($item as $i){
                        $ret[$i->id] = array_get($i->value, $labelcolumn);
                    }
                    return $ret;
                }
                return [$item->id => array_get($item->value, $labelcolumn)];
            }else{
                return [];
            }
        }
        return getModelName($table)::all()->pluck("value.{$labelcolumn}", "id");
    }
}

if (!function_exists('getOptionAjaxUrl')) {
    /**
     * get ajax url for options for select, multipleselect.
     *
     * @param array|CustomTable $table
     * @param $value
     */
    function getOptionAjaxUrl($table)
    {
        $table = CustomTable::getEloquent($table);
        if (is_numeric($table)) {
            $table = CustomTable::find($table);
        }
        else if (is_string($table)) {
            $table = CustomTable::findByName($table);
        } else if($table instanceof CustomValue) {
            $table = $table->getCustomTable();
        } else {
            $table = $table;
        }

        // get count table.
        $count = getModelName($table)::count();
        // when count > 0, create option only value.
        if ($count <= 100) {
            return null;
        }
        return admin_base_path("api/".array_get($table, 'table_name')."/query");
    }
}


if (!function_exists('createSelectOptions')) {
    /**
     * Create laravel-admin select box options.
     */
    function createSelectOptions($obj, $isValueText)
    {
        $options = [];
        if (is_null($obj)) {
            return $options;
        }

        if (is_string($obj)) {
            $str = str_replace(array("\r\n","\r","\n"), "\n", $obj);
            if (isset($str) && mb_strlen($str) > 0) {
                // 改行で分割してループ
                $array = explode("\n", $str);
                foreach ($array as $a) {
                    setSelectOptionItem($a, $options, $isValueText);
                }
            }
        } elseif (is_array($obj)) {
            foreach ($obj as $key => $value) {
                setSelectOptionItem($value, $options, $isValueText);
            }
        }

        return $options;
    }
}

if (!function_exists('setSelectOptionItem')) {
    /**
     * Create laravel-admin select box option item.
     */
    function setSelectOptionItem($item, &$options, $isValueText)
    {
        if (is_string($item)) {
            // $isValueText is true(split comma)
            if ($isValueText) {
                $splits = explode(',', $item);
                // 1つ以上であれば(","があれば)
                if (count($splits) > 1) {
                    $options[trim($splits[0])] = trim($splits[1]);
                } else {
                    $options[trim($splits[0])] = trim($splits[0]);
                }
            } else {
                $options[trim($item)] = trim($item);
            }
        }
    }
}

if (!function_exists('getColumnsSelectOptions')) {
    /**
     * get columns select options. It contains system column(ex. id, suuid, created_at, updated_at), and table columns.
     * @param array|CustomTable $table
     * @param $selected_value
     */
    function getColumnsSelectOptions($table, $search_enabled_only = false)
    {
        $options = [];
        ///// get system columns
        foreach(Define::VIEW_COLUMN_SYSTEM_OPTIONS as $option){
            // not header, continue
            if(!boolval(array_get($option, 'header'))){
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('custom_column.system_columns.'.array_get($option, 'name'));
        }

        // get table columns
        $table = CustomTable::getEloquent($table);
        $custom_columns = $table->custom_columns;
        foreach($custom_columns as $option){
            // if $search_enabled_only = true and options.search_enabled is false, continue
            if($search_enabled_only && !boolval(array_get($option, 'options.search_enabled'))){
                continue;
            }
            $options[array_get($option, 'id')] = array_get($option, 'column_view_name');
        }
        ///// get system columns
        foreach(Define::VIEW_COLUMN_SYSTEM_OPTIONS as $option){
            // not footer, continue
            if(!boolval(array_get($option, 'footer'))){
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('custom_column.system_columns.'.array_get($option, 'name'));
        }
    
        return $options;
    }
}

if (!function_exists('getRequestSession')) {
    /**
     * Get (such as) avaivable session in request.
     */
    function getRequestSession($key)
    {
        $config_key = "exment_global.$key";
        return config($config_key);
    }
}

if (!function_exists('setRequestSession')) {
    /**
     * Set (such as) avaivable session in request.
     */
    function setRequestSession($key, $value)
    {
        $config_key = "exment_global.$key";
        config([$config_key => $value]);
    }
}
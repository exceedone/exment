<?php
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

if (!function_exists('getManualUrl')) {
    function getManualUrl($uri)
    {
        $manual_url_base = config('exment.manual_url');
        // if ja, set
        if (config('app.locale') == 'ja') {
            $manual_url_base = url_join($manual_url_base, 'ja');
        }
        $manual_url_base = url_join($manual_url_base, $uri);
        return $manual_url_base;
    }
}
if (!function_exists('mbTrim')) {
    function mbTrim($pString)
    {
        if (is_null($pString)) {
            return null;
        }
        return preg_replace('/\A[\p{C}\p{Z}]++|[\p{C}\p{Z}]++\z/u', '', $pString);
    }
}

if (!function_exists('is_nullorempty')) {
    function is_nullorempty($obj)
    {
        if (is_null($obj)) {
            return true;
        }
        if (is_string($obj) && strlen($obj) == 0) {
            return true;
        }
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
if (!function_exists('parseFloat')) {
    /**
     * parseFloat
     */
    function parseFloat($num)
    {
        if (is_null($num)) {
            return null;
        }
        return floatval(str_replace(",", "", $num));
    }
}

if (!function_exists('hex2rgb')) {
    function hex2rgb($hex)
    {
        if (substr($hex, 0, 1) == "#") {
            $hex = substr($hex, 1) ;
        }
        if (strlen($hex) == 3) {
            $hex = substr($hex, 0, 1) . substr($hex, 0, 1) . substr($hex, 1, 1) . substr($hex, 1, 1) . substr($hex, 2, 1) . substr($hex, 2, 1) ;
        }
        return array_map("hexdec", [ substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2) ]) ;
    }
}

// File, path  --------------------------------------------------
if (!function_exists('namespace_join')) {
    /**
     * Join NameSpace.
     */
    function namespace_join(...$pass_array)
    {
        return join_paths('\\', $pass_array);
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
            if (is_array($value)) {
                $ret_pass = $ret_pass.$trim_str.join_paths($trim_str, $value);
            } elseif ($ret_pass == "") {
                $ret_pass   =   $value;
            } else {
                $ret_pass   =   rtrim($ret_pass, $trim_str);
                $value      =   ltrim($value, $trim_str);
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
        $basename = 'App\Plugins';
        if (isset($pass_array) && count($pass_array) > 0) {
            // convert to pascal case
            $pass_array = collect($pass_array)->map(function ($p) {
                return pascalize($p);
            })->toArray();

            $pass_array = array_prepend($pass_array, $basename);
            $basename = namespace_join($pass_array);
        }
        return $basename;
    }
}

if (!function_exists('mb_basename')) {
    function mb_basename($str, $suffix=null)
    {
        $tmp = preg_split('/[\/\\\\]/', $str);
        $res = end($tmp);
        if (strlen($suffix)) {
            $suffix = preg_quote($suffix);
            $res = preg_replace("/({$suffix})$/u", "", $res);
        }
        return $res;
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
        if (!is_array($keys)) {
            $keys = [$keys];
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
        if (is_null($array)) {
            return false;
        }
        if (!is_array($key)) {
            $key = [$key];
        }
        foreach ($key as $k) {
            if (!array_has($array, $k)) {
                continue;
            }
            if (!empty(array_get($array, $k))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_dot_reverse')) {
    /**
     * convert dotted_array to array
     * @return array
     */
    function array_dot_reverse($array)
    {
        if (is_null($array)) {
            return null;
        }
        $array_reverse = [];
        foreach ($array as $key => $value) {
            array_set($array_reverse, $key, $value);
        }
        return $array_reverse;
    }
}


function is_json($string)
{
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
        } else {
            array_push($validates, 'nullable');
        }
        array_push($validates, 'confirmed');
        array_push($validates, 'min:'.(!is_null(config('exment.password_rule.min')) ? config('exment.password_rule.min') : '8'));
        array_push($validates, 'max:'.(!is_null(config('exment.password_rule.max')) ? config('exment.password_rule.max') : '32'));
        
        if (!is_null(config('exment.password_rule.rule'))) {
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
        ///// get request session
        // stop db access too much
        if (is_numeric($obj) || is_string($obj)) {
            // has request session, set suuid
            if (!is_null(getRequestSession('getModelName_'.$obj))) {
                $suuid = getRequestSession('getModelName_'.$obj);
            }
        }

        // not has suuid(first call), set suuid and request session
        if (!isset($suuid)) {
            if (is_numeric($obj)) {
                // Get suuid.
                // using DB query builder (because this function may be called createCustomTableTrait. this function is trait CustomTable
                //$table = CustomTable::find($obj);
                $suuid = DB::table('custom_tables')->where('id', $obj)->first()->suuid ?? null;
                setRequestSession('getModelName_'.$obj, $suuid);
            } elseif (is_string($obj)) {
                // get by table_name
                // $table = CustomTable::findByName($obj);
                $suuid = DB::table('custom_tables')->where('table_name', $obj)->first()->suuid ?? null;
                setRequestSession('getModelName_'.$obj, $suuid);
            } elseif ($obj instanceof CustomValue) {
                $table = $obj->getCustomTable();
                $suuid = $table->suuid;
            } elseif (is_null($obj)) {
                return null; // TODO: It's OK???
            } else {
                $table = $obj;
                $suuid = $table->suuid;
            }
        }

        $namespace = "App\\CustomModel";
        $className = "Class_{$suuid}";
        $fillpath = "{$namespace}\\{$className}";
        // if the model doesn't defined, and $get_name_only is false
        // create class dynamically.
        if (!$get_name_only && !class_exists($fillpath)) {
            // get table. this block isn't called by createCustomTableTrait
            $table = CustomTable::findBySuuid($suuid);
            createTable($table);
            ClassBuilder::createCustomValue($namespace, $className, $fillpath, $table, $obj);
        }

        return "\\".$fillpath;
    }
}
if (!function_exists('getCustomTableTrait')) {
    /**
     * For use function in "CustomTable"、create CustomTableTrait class
     * @param string|CustomTable $obj
     * @return string
     */
    function getCustomTableTrait()
    {
        $namespace = "Exceedone\\Exment\\Model\\Traits";
        $className = "CustomTableDynamicTrait";
        $fillpath = "{$namespace}\\{$className}";
        // if the model doesn't defined
        if (!class_exists($fillpath)) {
            ClassBuilder::createCustomTableTrait($namespace, $className, $fillpath);
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
     * @param boolean $label if get the columnname only get column label.
     * @return string
     */
    function getColumnName($column_obj, $label = false)
    {
        $column_obj = CustomColumn::getEloquent($column_obj);
        return 'column_'.array_get($column_obj, 'suuid').($label ? '_label' : '');
    }
}

if (!function_exists('getColumnNameByTable')) {
    /**
     * Get column name using table model.
     * @param string|CustomTable|array $obj
     * @return string
     */
    function getColumnNameByTable($table_obj, $column_name)
    {
        // get column eloquent
        $column_obj = CustomColumn::getEloquent($column_name, $table_obj);
        // return column name
        return getColumnName($column_obj);
    }
}

if (!function_exists('getRelationName')) {
    /**
     * Get relation name.
     * @param CustomRelation $relation_obj
     * @return string
     */
    function getRelationName($relation_obj)
    {
        return getRelationNamebyObjs($relation_obj->parent_custom_table, $relation_obj->child_custom_table);
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
        $parent_suuid = CustomTable::getEloquent($parent)->suuid ?? null;
        $child_suuid = CustomTable::getEloquent($child)->suuid ?? null;
        if (is_null($parent_suuid) || is_null($child_suuid)) {
            return null;
        }
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
        if (!System::authority_available()) {
            return;
        }
        
        // get Authority setting
        $authorities = Authority::where('authority_type', $related_type)->get();
        foreach ($authorities as $authority) {
            $related_types = [Define::SYSTEM_TABLE_NAME_USER];
            // if use organization, add
            if (System::organization_available()) {
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
     * @param CustomValue $custom_value
     * @param string|array|CustomColumn $column
     * @param bool $isonly_label if column_type is select_table or select_valtext, only get label
     * @return string
     */
    function getValue($custom_value, $column = null, $isonly_label = false, $format = '')
    {
        if (is_null($custom_value)) {
            return $isonly_label ? '' : null;
        }

        $isCollection = $custom_value instanceof \Illuminate\Database\Eloquent\Collection;
        // if multible data, set collection, so set as array
        if (!$isCollection) {
            $custom_value = [$custom_value];
        }

        $custom_table = $custom_value[0]->getCustomTable();

        // get value
        $values = [];
        foreach ($custom_value as $v) {
            $value = $v->value;
            if (is_null($value)) {
                continue;
            }
            
            $values[] = getValueUseTable($custom_table, $value, $column, $isonly_label, $format);
        }

        // if is collection
        if ($isCollection) {
            // if isonly label, return comma string
            if ($isonly_label) {
                return implode(exmtrans('common.separate_word'), $values);
            }
            return collect($values);
        }
        if (count($values) == 0) {
            return null;
        }
        return $values[0];
    }
}

if (!function_exists('getValueUseTable')) {
    /**
     * Get Custom Value
     * @param array|CustomValue $value trget value
     * @param string|array|CustomColumn $column target column_name or CustomColumn object. If null, it's label column
     * @param mixin $label if column_type is select_table or select_valtext, only get label.
     * @return string
     */
    function getValueUseTable($custom_table, $value, $column = null, $label = false, $format = '')
    {
        if (is_null($value)) {
            return null;
        }
        $custom_table = CustomTable::getEloquent($custom_table);
        if (is_null($column)) {
            return getLabelUseTable($custom_table, $value);
        }

        // if $column is string and  and contains comma
        if (is_string($column) && str_contains($column, ',')) {
            ///// getting value Recursively
            // split comma
            $columns = explode(",", $column);
            // if $columns count >= 2, loop columns
            if (count($columns) >= 2) {
                $loop_value = $value;
                $loop_custom_table = $custom_table;
                foreach ($columns as $k => $c) {
                    $lastIndex = ($k == count($columns) - 1);
                    // if $k is not last index, $loop_label is false(because using CustomValue Object)
                    if (!$lastIndex) {
                        $loop_label = false;
                    }
                    // if last index, $loop_label is called $label
                    else {
                        $loop_label = $label;
                    }
                    // get value using $c
                    $loop_value = getValueUseTable($loop_custom_table, $loop_value, $c, $loop_label);
                    // if null, return
                    if (is_null($loop_value)) {
                        return null;
                    }

                    // if last index, return value
                    if ($lastIndex) {
                        return $loop_value;
                    }

                    // get custom table. if CustomValue
                    if ($loop_value instanceof CustomValue) {
                        $loop_custom_table = $loop_value->getCustomTable();
                    }
                    // else, something wrong, so return null
                    else {
                        return null;
                    }
                }
                return $loop_value;
            }
            // if length <= 1, set normal getValueUseTable flow, so $column = $columns[0]
            else {
                $column = $columns[0];
            }
        }

        ///// get custom column as array
        // if string
        if (is_string($column)) {
            $column_first = CustomColumn
                ::where('column_name', $column)
                ->where('custom_table_id', array_get($custom_table, 'id'))
                ->first();
            if (is_null($column_first)) {
                return null;
            }
            $column_array = $column_first->toArray() ?? null;
        }
        // if $column is CustomColumn, convert to array.
        elseif ($column instanceof CustomColumn) {
            $column_array = $column->toArray();
        } else {
            $column_array = $column;
        }

        if (is_null($column_array)) {
            return null;
        }

        if (is_array($value)) {
            $key = array_get($column_array, 'column_name');
            $val = array_get($value, $key);
        } elseif ($value instanceof CustomValue) {
            $key = array_get($column_array, 'column_name');
            $val = array_get($value->value, $key);
        } else {
            $val = $value;
        }

        if (is_null($val)) {
            return null;
        }
        $column_type = array_get($column_array, 'column_type');

        // calcurate  --------------------------------------------------
        if (in_array($column_type, ['decimal', 'currency'])) {
            $val = parseFloat($val);
            if (array_has($column_array, 'options.decimal_digit')) {
                $digit = intval(array_get($column_array, 'options.decimal_digit'));
                $val = floor($val * pow(10, $digit)) / pow(10, $digit);
            }
        }

        // return finally value --------------------------------------------------
        // get value as select
        // get value as select_valtext
        if (in_array($column_type, ['select', 'select_valtext'])) {
            $array_get_key = $column_type == 'select' ? 'options.select_item' : 'options.select_item_valtext';
            $select_item = array_get($column_array, $array_get_key);
            $options = createSelectOptions(CustomColumn::getEloquent($column, $custom_table));
            if (!array_keys_exists($val, $options)) {
                return null;
            }

            // if $val is array
            $multiple = true;
            if (!is_array($val)) {
                $val = [$val];
                $multiple = false;
            }
            // switch column_type and get return value
            $returns = [];
            switch ($column_type) {
                case 'select':
                    $returns = $val;
                    break;
                case 'select_valtext':
                    // loop keyvalue
                    foreach ($val as $v) {
                        // set whether $label
                        $returns[] = $label ? array_get($options, $v) : $v;
                    }
                    break;
            }
            if ($multiple) {
                return $label ? implode(exmtrans('common.separate_word'), $returns) : $returns;
            } else {
                return $returns[0];
            }
        }

        // get value as select_table
        elseif (in_array($column_type, ['select_table', 'user', 'organization'])) {
            // get target table
            $target_table_key = null;
            if ($column_type == 'select_table') {
                $target_table_key = array_get($column_array, 'options.select_target_table');
            } elseif (in_array($column_type, [Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])) {
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
            
            // if $model is array multiple, set as array
            if (!($model instanceof \Illuminate\Database\Eloquent\Collection)) {
                $model = [$model];
            }

            $labels = [];
            foreach ($model as $m) {
                if (is_null($column)) {
                    continue;
                }
                 
                // get label column
                // if label is true, return getLabel
                if ($label === true) {
                    $labels[] = $m->label;
                }
                // if label is selecting column name, get target label
                elseif (is_string($label)) {
                    $labels[] = CustomColumn::where('custom_table_id', $target_table['id'])->where('column_name', $label)->first();
                }
            }
            return implode(exmtrans('common.separate_word'), $labels);
        } elseif (in_array($column_type, ['file', 'image'])) {
            // Whether multiple file.
            $multiple_enabled = boolval(array_get($column_array, 'options.multiple_enabled'));

            if ($multiple_enabled) {
                // todo:return multiple files;
            } else {
                // get file
                if ($label !== true) {
                    $file = File::getFile($val);
                    return $file;
                }
                return $val;
            }
        }
        // yesno
        elseif (in_array($column_type, ['yesno'])) {
            if ($label !== true) {
                return $val;
            }
            // convert label
            return boolval($val) ? 'YES' : 'NO';
        }
        // boolean
        elseif (in_array($column_type, ['yesno'])) {
            if ($label !== true) {
                return $val;
            }
            // convert label
            // check matched true and false value
            if (array_get($column_array, 'options.true_value') == $val) {
                return array_get($column_array, 'options.true_label');
            } elseif (array_get($column_array, 'options.false_value') == $val) {
                return array_get($column_array, 'options.false_label');
            }
            return null;
        }
        // currency
        elseif (in_array($column_type, ['currency'])) {
            // if not label, return
            if ($label !== true) {
                return $val;
            }
            if (boolval(array_get($column_array, 'options.number_format')) && is_numeric($val)) {
                $val = number_format($val);
            }
            // get symbol
            $symbol = array_get($column_array, 'options.currency_symbol');
            return getCurrencySymbolLabel($symbol, $val);
        }
        // datetime, date
        elseif (in_array($column_type, ['datetime', 'date'])) {
            // if not empty format, using carbon
            if (!is_nullorempty($format)) {
                return (new \Carbon\Carbon($val))->format($format) ?? null;
            }
            // else, return
            return $val;
        } else {
            // if not label, return
            if ($label !== true) {
                return $val;
            }
            if (boolval(array_get($column_array, 'options.number_format')) && is_numeric($val)) {
                $val = number_format($val);
            }
            return $val;
        }
    }
}

if (!function_exists('getLabel')) {
    /**
     * Get label text
     * @param CustomValue|array $custom_value
     * @param CustomTable|array $obj
     * @return string
     */
    function getLabel($custom_value)
    {
        if (is_null($custom_value)) {
            return null;
        }
        $custom_table = $custom_value->getCustomTable();
        return getLabelUseTable($custom_table, array_get($custom_value, 'value'));
    }
}

if (!function_exists('getLabelUseTable')) {
    /**
     * Get label text
     * @param CustomTable $custom_table
     * @param CustomValue|array $custom_value
     * @return string
     */
    function getLabelUseTable($custom_table, $value)
    {
        if (is_null($value)) {
            return null;
        }
        $columns = $custom_table->custom_columns()
            ->whereNotIn('options->use_label_flg', [0, "0"])
            ->orderBy('options->use_label_flg')
            ->get();
        if (!isset($columns)) {
            $columns = collect($custom_table->custom_columns()->first());
        }

        // loop for columns and get value
        $labels = [];
        foreach ($columns as $column) {
            if (!isset($column)) {
                continue;
            }
            $label = getValueUseTable($custom_table, $value, $column, true);
            if (!isset($label)) {
                continue;
            }
            $labels[] = $label;
        }

        return implode(' ', $labels);
    }
}


if (!function_exists('getParentValue')) {
    /**
     * get parent value
     */
    function getParentValue($custom_value, $isonly_label = false)
    {
        $model = getModelName($custom_value->parent_type)::find($custom_value->parent_id);
        
        if (!$isonly_label) {
            return $model;
        }
        return $model->label;
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

if (!function_exists('getCurrencySymbolLabel')) {
    /**
     */
    function getCurrencySymbolLabel($symbol, $value = '123,456.00')
    {
        $symbol_item = array_get(Define::CUSTOM_COLUMN_CURRENCYLIST, $symbol);
        if (isset($symbol_item)) {
            if (array_get($symbol_item, 'type') == 'before') {
                $text = "$symbol$value";
            } else {
                $text = "$value$symbol";
            }
            return $text;
        }
        return null;
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
            if (boolval(array_get($custom_column, 'options.search_enabled'))) {
                array_push($column_arrays, $custom_column);
            }
        }
        return $column_arrays;
    }
}


if (!function_exists('getUrl')) {
    /**
     * Get url for column_type is url, select_table.
     * @param CustomValue $custom_value
     * @param CustomColumn $column
     * @return string
     */
    function getUrl($custom_value, $column, $tag = false)
    {
        if (is_null($custom_value)) {
            return null;
        }
        $url = null;
        $value = $custom_value->getValue($column, true);
        switch ($column->column_type) {
            case 'url':
                $url = $custom_value->getValue($column);
                if (!$tag) {
                    return $url;
                }
                return "<a href='{$url}' target='_blank'>$value</a>";
            case 'select_table':
                $target_value = $custom_value->getValue($column);
                $id =  $target_value->id ?? null;
                if (!isset($id)) {
                    return null;
                }
                // create url
                return $target_value->getUrl($tag);
        }

        return null;
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
        if (!isset($table_name)) {
            throw new Exception('table name is not found. please tell system administrator.');
        }

        // check already execute
        $key = getRequestSession('create_table.'.$table_name);
        if (boolval($key)) {
            return;
        }

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
        // Create index --------------------------------------------------
        $table = CustomTable::findByName($table_name);
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
        if ($exists && ($forceDropIndex || (!boolval($search_enabled)))) {
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
        elseif ($search_enabled && !$exists) {
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
    }
}

if (!function_exists('getEndpointTable')) {
    /**
     * Get table object using endpoint name.
     */
    function getEndpointTable($endpoint = null)
    {
        if (!isset($endpoint)) {
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
            if (in_array($url, ['index', 'create', 'show', 'edit'])) {
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

if (!function_exists('disableFormFooter')) {
    /**
     * disable form footer items
     *
     */
    function disableFormFooter($form)
    {
        $form->footer(function ($footer) {
            // disable reset btn
            $footer->disableReset();
            // disable `View` checkbox
            $footer->disableViewCheck();
            // disable `Continue editing` checkbox
            $footer->disableEditingCheck();
            // disable `Continue Creating` checkbox
            $footer->disableCreatingCheck();
        });
    }
}
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
        $count = getOptionsQuery($table)::count();
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
    function getOptions($table, $selected_value = null)
    {
        if (is_null($table)) {
            return [];
        }
        // get count table.
        $count = getOptionsQuery($table)::count();
        // when count > 0, create option only value.
        if ($count > 100) {
            if (!isset($selected_value)) {
                return [];
            }
            $item = getOptionsQuery($table)::find($selected_value);

            if ($item) {
                // check whether $item is multiple value.
                if ($item instanceof Collection) {
                    $ret = [];
                    foreach ($item as $i) {
                        $ret[$i->id] = $i->label;
                    }
                    return $ret;
                }
                return [$item->id => $item->label];
            } else {
                return [];
            }
        }
        return getOptionsQuery($table)::get()->pluck("label", "id");
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
        if (is_null($table)) {
            return null;
        }
        $table = CustomTable::getEloquent($table);
        // get count table.
        $count = getOptionsQuery($table)::count();
        // when count > 0, create option only value.
        if ($count <= 100) {
            return null;
        }
        return admin_base_path(url_join("api", array_get($table, 'table_name'), "query"));
    }
}

if (!function_exists('getOptionsQuery')) {
    /**
     * getOptionsQuery. this function uses for count, get, ...
     */
    function getOptionsQuery($table)
    {
        // get model
        $modelname = getModelName($table);
        $model = new $modelname;

        // filter model
        $model = Admin::user()->filterModel($model, $table);
        return $model;
    }
}


if (!function_exists('createSelectOptions')) {
    /**
     * Create laravel-admin select box options. for column_type "select", "select_valtext"
     */
    function createSelectOptions($column)
    {
        // get value
        $column_type = array_get($column, 'column_type');
        $column_options = array_get($column, 'options');

        // get select item string
        $array_get_key = $column_type == 'select' ? 'select_item' : 'select_item_valtext';
        $select_item = array_get($column_options, $array_get_key);
        $isValueText = ($column_type == 'select_valtext');
        
        $options = [];
        if (is_null($select_item)) {
            return $options;
        }

        if (is_string($select_item)) {
            $str = str_replace(array("\r\n","\r","\n"), "\n", $select_item);
            if (isset($str) && mb_strlen($str) > 0) {
                // loop for split new line
                $array = explode("\n", $str);
                foreach ($array as $a) {
                    setSelectOptionItem($a, $options, $isValueText);
                }
            }
        } elseif (is_array($select_item)) {
            foreach ($select_item as $key => $value) {
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
                if (count($splits) > 1) {
                    $options[mbTrim($splits[0])] = mbTrim($splits[1]);
                } else {
                    $options[mbTrim($splits[0])] = mbTrim($splits[0]);
                }
            } else {
                $options[mbTrim($item)] = mbTrim($item);
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
        $table = CustomTable::getEloquent($table);
        $options = [];
        
        ///// get system columns
        foreach (Define::VIEW_COLUMN_SYSTEM_OPTIONS as $option) {
            // not header, continue
            if (!boolval(array_get($option, 'header'))) {
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('custom_column.system_columns.'.array_get($option, 'name'));
        }

        ///// if this table is child relation(1:n), add parent table
        $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $table->id)->first();
        if (isset($relation)) {
            $options['parent_id'] = array_get($relation, 'parent_custom_table.table_view_name');
        }

        ///// get table columns
        $custom_columns = $table->custom_columns;
        foreach ($custom_columns as $option) {
            // if $search_enabled_only = true and options.search_enabled is false, continue
            if ($search_enabled_only && !boolval(array_get($option, 'options.search_enabled'))) {
                continue;
            }
            $options[array_get($option, 'id')] = array_get($option, 'column_view_name');
        }
        ///// get system columns
        foreach (Define::VIEW_COLUMN_SYSTEM_OPTIONS as $option) {
            // not footer, continue
            if (!boolval(array_get($option, 'footer'))) {
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


if (!function_exists('getAjaxResponse')) {
    /**
     * get ajax response.
     * using plugin, copy, data import/export
     */
    function getAjaxResponse($results)
    {
        if ($results instanceof \Illuminate\Http\Response) {
            return $results;
        }
        if (is_bool($results)) {
            $results = ['result' => $results];
        }
        $results = array_merge([
            'result' => true,
            'toastr' => null,
            'errors' => [],
        ], $results);

        return response($results, $results['result'] === true ? 200 : 400);
    }
}


// Excel --------------------------------------------------
if (!function_exists('getDataFromSheet')) {
    /**
     * get Data from excel sheet
     */
    function getDataFromSheet($sheet, $skip_excel_row_no = 0, $keyvalue = false)
    {
        $data = [];
        foreach ($sheet->getRowIterator() as $row_no => $row) {
            // if index < $skip_excel_row_no, conitnue
            if ($row_no <= $skip_excel_row_no) {
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // This loops through all cells,
            $cells = [];
            foreach ($cellIterator as $column_no => $cell) {
                $value = getCellValue($cell, $sheet);

                // if keyvalue, set array as key value
                if ($keyvalue) {
                    $key = getCellValue($column_no."1", $sheet);
                    $cells[$key] = mbTrim($value);
                }
                // if false, set as array
                else {
                    $cells[] = mbTrim($value);
                }
            }
            if (collect($cells)->filter(function ($v) {
                return !is_nullorempty($v);
            })->count() == 0) {
                break;
            }
            $data[] = $cells;
        }

        return $data;
    }
}

if (!function_exists('getCellValue')) {
    /**
     * get cell value
     */
    function getCellValue($cell, $sheet)
    {
        if (is_string($cell)) {
            $cell = $sheet->getCell($cell);
        }
        $value = $cell->getCalculatedValue();
        // is datetime, convert to date string
        if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($value)) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
            $value = ctype_digit(strval($value)) ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
        }
        // if rich text, set plain value
        elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        return $value;
    }
}

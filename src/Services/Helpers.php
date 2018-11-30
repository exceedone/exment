<?php
use Exceedone\Exment\Services\ClassBuilder;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\ModelBase;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewColumnType;
use Illuminate\Support\Str;
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
    function getManualUrl($uri = null)
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

if (!function_exists('esc_html')) {
    /**
     * escape html
     */
    function esc_html($str)
    {
        return htmlspecialchars($str, ENT_QUOTES|ENT_HTML5);
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
if (!function_exists('file_ext')) {
    /**
     * get file extension
     */
    function file_ext($filename)
    {
        return preg_match('/\./', $filename) ? preg_replace('/^.*\./', '', $filename) : '';
    }
}
if (!function_exists('file_ext_strip')) {
    /**
     * Returns the file name, less the extension.
     */
    function file_ext_strip($filename)
    {
        return preg_replace('/.[^.]*$/', '', $filename);
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
            $table->createTable();
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
        if (!isset($obj)) {
            throw new Exception('table name is not found. please tell system administrator.');
        }
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

if (!function_exists('getIndexColumnName')) {
    /**
     * Get column name. This function uses only search-enabled column.
     * @param CustomColumn|array $obj
     * @param boolean $label if get the columnname only get column label.
     * @return string
     */
    function getIndexColumnName($column_obj, $label = false)
    {
        $column_obj = CustomColumn::getEloquent($column_obj);
        return 'column_'.array_get($column_obj, 'suuid').($label ? '_label' : '');
    }
}

if (!function_exists('getIndexColumnNameByTable')) {
    /**
     * Get column name using table model.
     * @param string|CustomTable|array $obj
     * @return string
     */
    function getIndexColumnNameByTable($table_obj, $column_name)
    {
        // get column eloquent
        $column_obj = CustomColumn::getEloquent($column_name, $table_obj);
        // return column name
        return getIndexColumnName($column_obj);
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

if (!function_exists('getCurrencySymbolLabel')) {
    /**
     * Get Currency Sybmol. ex. $, ￥, ...
     */
    function getCurrencySymbolLabel($symbol, $value = '123,456.00')
    {
        $symbol_item = array_get(Define::CUSTOM_COLUMN_CURRENCYLIST, $symbol);
        // replace &yen; to ¥
        // TODO: change logic how to manage mark
        $symbol = str_replace("&yen;", '¥', $symbol);
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

if (!function_exists('getAuthorityUser')) {
    /**
     * get users who has authorities.
     */
    function getAuthorityUser($target_table, $related_type)
    {
        if (is_null($target_table)) {
            return [];
        }
        $target_table = CustomTable::getEloquent($target_table);

        // get user or organiztion ids
        $target_ids = DB::table('authorities as a')
            ->join(SystemTableName::SYSTEM_AUTHORITABLE.' AS sa', 'a.id', 'sa.authority_id')
            ->whereIn('related_type', $related_type)
            ->where(function ($query) use ($target_table) {
                $query->orWhere(function ($query) {
                    $query->where('morph_type', AuthorityType::SYSTEM);
                });
                $query->orWhere(function ($query) use ($target_table) {
                    $query->where('morph_type', AuthorityType::TABLE)
                    ->where('morph_id', $target_table->id);
                });
            })->get(['related_id'])->pluck('related_id');
        
        // return target values
        return getModelName($related_type)::whereIn('id', $target_ids);
    }
}

if (!function_exists('replaceTextFromFormat')) {
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    function replaceTextFromFormat($format, $custom_value = null, $options = [])
    {
        if (is_null($format)) {
            return null;
        }

        $options = array_merge(
            [
                'matchBeforeCallback' => null,
                'afterCallBack' => null,
            ]
            , $options
        );

        try {
            // check string
            preg_match_all('/\${(.*?)\}/', $format, $matches);
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $str = null;
                    $matchString = null;
                    try {
                        $match = strtolower($matches[1][$i]);
                        $matchString = $matches[0][$i];
                    
                        // get length
                        $length_array = explode(":", $match);
                        $key = $length_array[0];
                        
                        // define date array
                        $dateStrings = [
                            'ymdhms' => 'YmdHis',
                            'ymdhm' => 'YmdHi',
                            'ymdh' => 'YmdH',
                            'ymd' => 'Ymd',
                            'ym' => 'Ym',
                            'hms' => 'His',
                            'hm' => 'Hi',
                        ];
                        $dateValues = [
                            'year',
                            'month',
                            'day',
                            'hour',
                            'monute',
                            'second',
                        ];

                        if(array_key_value_exists('matchBeforeCallback', $options)){
                            // execute callback
                            $callbackFunc = $options['matchBeforeCallback'];
                            $result = $callbackFunc->call($length_array, $match, $format, $custom_value, $options);
                            if($result){
                                $format = $result;
                                continue;
                            }
                        }

                        ///// id
                        if ($key == "id") {
                            // replace add zero using id.
                            if (count($length_array) > 1) {
                                $str = sprintf('%0'.$length_array[1].'d', $id);
                            } else {
                                $str = $id;
                            }
                        }
                        ///// value
                        elseif ($key == "value") {
                            if(!isset($custom_value)){
                                $str = $id_string;
                            }
                            // get value from model
                            elseif (count($length_array) <= 1) {
                                $str = '';
                            } else {
                                // get comma string from index 1.
                                $length_array = array_slice($length_array, 1);
                                $str = $custom_value->getValue(implode(',', $length_array), true, array_get($options, 'format'));
                            }
                        }
                        // base_info
                        elseif ($key == "base_info") {
                            $base_info = getModelName(SystemTableName::BASEINFO)::first();
                            if(!isset($base_info)){
                                $str = '';
                            }
                            // get value from model
                            elseif (count($length_array) <= 1) {
                                $str = '';
                            } else {
                                $str = $base_info->getValue($length_array[1], true, array_get($options, 'format'));
                            }
                        }
                        ///// sum
                        elseif ($key == "sum") {
                            if(!isset($custom_value)){
                                $str = '';
                            }

                            // get sum value from children model
                            elseif (count($length_array) <= 2) {
                                $str = '';
                            }
                            //else, getting value using cihldren
                            else {
                                // get children values
                                $children = $custom_value->getChildrenValues($length_array[1]) ?? [];
                                // looping
                                $sum = 0;
                                foreach ($children as $child) {
                                    // get value
                                    $sum += intval(str_replace(',', '', $child->getValue($length_array[2])));
                                }
                                $str = strval($sum);
                            }
                        }

                        // suuid
                        elseif ($key == "suuid") {
                            $str = short_uuid();
                        }
                        // uuid
                        elseif ($key == "uuid") {
                            $str = make_uuid();
                        }
                        // if has $datestrings, conbert using date string
                        elseif(array_key_exists($key, $dateStrings)){
                            $str = \Carbon\Carbon::now()->format($dateStrings[$key]);
                        }
                        // if has $datestrings, conbert using date value
                        elseif(array_has($dateValues, $key)){
                            $str = Carbon::now()->{$dateValues->$key};
                            // if user input length
                            if (count($length_array) > 1) {
                                $length = $length_array[1];
                            }
                            // default 2
                            else {
                                $length = 1;
                            }
                            $str = sprintf('%0'.$length.'d', $str);
                        }
                    } catch (\Exception $e) {
                        $str = '';
                    }

                    // replace 
                    $format = str_replace($matchString, $str, $format);
                }
            }
        } catch (\Exception $e) {

        }

        if(array_key_value_exists('afterCallback', $options)){
            // execute callback
            $callbackFunc = $options['afterCallback'];
            $format = $callbackFunc($format, $custom_value, $options);
        }
        return $format;
    }
}


// Database Difinition --------------------------------------------------

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
        if($array instanceof \MyCLabs\Enum\Enum){
            $array = array_flatten($array::toArray());
        }
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
     * @param array|CustomTable $table to get table object
     * @param $selected_value the value that already selected.
     * @param array|CustomTable $target_table Information on the table displayed on the screen
     * @param boolean $all is show all data. for system authority, it's true.
     */
    function getOptions($table, $selected_value = null, $target_table = null, $all = false)
    {
        if (is_null($table)) {
            return [];
        }
        if (is_null($target_table)) {
            $target_table = $table;
        }
        
        // get query.
        // if user or organization, get from getAuthorityUserOrOrg
        if (in_array($table, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && !$all) {
            $query = Authority::getAuthorityUserOrgQuery($target_table, $table);
        } else {
            $query = getOptionsQuery($table);
        }

        // get count table.
        $count = $query->count();
        // when count > 0, create option only value.
        if ($count > 100) {
            if (!isset($selected_value)) {
                return [];
            }
            $item = getModelName($table)::find($selected_value);

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
        return $query->get()->pluck("label", "id");
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
        foreach (ViewColumnType::SYSTEM_OPTIONS() as $option) {
            // not header, continue
            if (!boolval(array_get($option, 'header'))) {
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('common.'.array_get($option, 'name'));
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
        foreach (ViewColumnType::SYSTEM_OPTIONS() as $option) {
            // not footer, continue
            if (!boolval(array_get($option, 'footer'))) {
                continue;
            }
            $options[array_get($option, 'name')] = exmtrans('common.'.array_get($option, 'name'));
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

if (!function_exists('useLoginProvider')) {
    /**
     * use login provider
     */
    function useLoginProvider()
    {
        return !is_nullorempty(config('exment.login_providers'));
    }
}

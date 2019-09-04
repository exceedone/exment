<?php
use Exceedone\Exment\Services\ClassBuilder;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\ModelBase;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\CurrencySymbol;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Webpatser\Uuid\Uuid;
use Carbon\Carbon;

if (!function_exists('exmDebugLog')) {
    /**
     * Debug log
     */
    function exmDebugLog($log)
    {
        $now = Carbon::now();

        $log_string = $now->format("YmdHisv")." ".$log;

        \Log::debug($log_string);
    }
}

if (!function_exists('exmtrans')) {
    function exmtrans($key, ...$args)
    {
        if (count($args) > 0 && is_array($args[0])) {
            return trans("exment::exment.$key", $args[0]);
        }

        $trans = trans("exment::exment.$key");
        if (count($args) > 0) {
            $trans = vsprintf($trans, $args);
        }
        return $trans;
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

if (!function_exists('esc_script_tag')) {
    /**
     * escape only script tag
     */
    function esc_script_tag($html)
    {
        if (is_nullorempty($html)) {
            return $html;
        }
        
        try {
            libxml_use_internal_errors(true);

            $dom = new \DOMDocument();
    
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
            $script = $dom->getElementsByTagName('script');
    
            $remove = [];
            foreach ($script as $item) {
                $remove[] = $item;
            }
    
            foreach ($remove as $item) {
                $item->parentNode->removeChild($item);
            }
    
            $html = trim($dom->saveHTML());
            $html = preg_replace('/^<br>/u', '', $html);
            $html = preg_replace('/<br>$/u', '', $html);
            
            libxml_use_internal_errors(false);
        } catch (\Exception $ex) {
            return $html;
        }
        
        return $html;
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql($string)
    {
        return app('db')->getPdo()->quote($string);
    }
}

if (!function_exists('is_nullorempty')) {
    /**
     * validate string. null is true, "" is true, 0 and "0" is false.
     */
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

if (!function_exists('rmcomma')) {
    /**
     * remove comma
     */
    function rmcomma($value)
    {
        if (is_null($value)) {
            return null;
        }
        
        return str_replace(",", "", $value);
    }
}

// File, path  --------------------------------------------------
if (!function_exists('exment_path')) {

    /**
     * Get exment path.
     *
     * @param string $path
     *
     * @return string
     */
    function exment_path($path = '')
    {
        return ucfirst(config('exment.directory', app_path('Exment'))).($path ? DIRECTORY_SEPARATOR.$path : $path);
    }
}

if (!function_exists('admin_urls')) {
    /**
     * Join admin url paths.
     */
    function admin_urls(...$pass_array)
    {
        return admin_url(url_join($pass_array));
    }
}

if (!function_exists('admin_base_paths')) {
    /**
     * Join admin base paths.
     */
    function admin_base_paths(...$pass_array)
    {
        return admin_base_path(url_join($pass_array));
    }
}

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
            if (empty($value)) {
                continue;
            }
            
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

if (!function_exists('storage_paths')) {
    function storage_paths(...$pass_array)
    {
        return path_join(storage_path(), ...$pass_array);
    }
}

if (!function_exists('app_paths')) {
    function app_paths(...$pass_array)
    {
        return path_join(app_path(), ...$pass_array);
    }
}

if (!function_exists('getFullpath')) {
    function getFullpath($filename, $disk, $mkdir = false)
    {
        $path = Storage::disk($disk)->getDriver()->getAdapter()->applyPathPrefix($filename);

        if ($mkdir) {
            $dirPath = pathinfo($path)['dirname'];
            if (!\File::exists($dirPath)) {
                \File::makeDirectory($dirPath, 0755, true);
            }
        }
        return $path;
    }
}

if (!function_exists('getTmpFolderPath')) {
    /**
     * get tmp folder path. Uses for
     * @param string $type "plugin", "template", "backup", "data".
     */
    function getTmpFolderPath($type, $fullpath = true)
    {
        $path = path_join('tmp', $type);
        if (!$fullpath) {
            return $path;
        }
        $tmppath = getFullpath($path, Define::DISKNAME_ADMIN_TMP);
        if (!\File::exists($tmppath)) {
            \File::makeDirectory($tmppath, 0755, true);
        }

        return $tmppath;
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

if (!function_exists('bytesToHuman')) {
    function bytesToHuman($bytes, $default = null)
    {
        if (is_null($bytes)) {
            return $default;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}

if (!function_exists('getUploadMaxFileSize')) {
    /**
     * get Upload Max File Size. get php.ini config
     *
     * @return int byte size.
     */
    function getUploadMaxFileSize()
    {
        $post_max_size = (int)(str_replace('M', '', ini_get('post_max_size')));
        $upload_max_filesize = (int)(str_replace('M', '', ini_get('upload_max_filesize')));

        // return min size post_max_size or upload_max_filesize
        $minsize = collect([$post_max_size, $upload_max_filesize])->min();

        // return byte size
        return $minsize * 1024 * 1024;
    }
}

if (!function_exists('isApiEndpoint')) {
    /**
     * this url is ApiEndpoint
     */
    function isApiEndpoint()
    {
        $basePath = ltrim(admin_base_path(), '/');
        return request()->is($basePath . '/api/*') || request()->is($basePath . '/webapi/*');
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
        if (is_string($array)) {
            $array = [$array];
        }
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

if (!function_exists('array_dot_only')) {
    /**
     * arrayonly with dot
     * @return array
     */
    function array_dot_only($array, $keys)
    {
        $newArray = [];
        foreach ((array) $keys as $key) {
            array_set($newArray, $key, array_get($array, $key));
        }
        return $newArray;
    }
}

if (!function_exists('is_json')) {
    function is_json($string)
    {
        return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }
}


if (!function_exists('is_vector')) {
    /**
     * whether array is vector array(not associative array)
     */
    function is_vector(array $arr)
    {
        return array_values($arr) === $arr;
    }
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
        // replace A to _a
        $string = preg_replace_callback('/[A-Z]/', function ($match) {
            return '_' . strtolower($match[0]);
        }, $string);
        $string = ltrim($string, '_');
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

if (!function_exists('get_omitted_string')) {
    /**
     * if over string length. remove text, add "..."
     * @return string
     */
    function get_omitted_string($text, $length = Define::GRID_MAX_LENGTH)
    {
        if (is_null($text)) {
            return $text;
        }

        if (gettype($text) != 'string') {
            return $text;
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '...';
    }
}

if (!function_exists('replaceBreak')) {
    /**
     * replace new line code to <br />
     * @return string
     */
    function replaceBreak($text, $isescape = true)
    {
        return preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", $isescape ? esc_script_tag($text) : $text);
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
        if ($obj instanceof CustomValue) {
            $table = $obj->custom_table;
            $suuid = $table->suuid;
        } elseif ($obj instanceof CustomTable) {
            $suuid = $obj->suuid;
        } elseif (is_numeric($obj) || is_string($obj)) {
            // get all table info
            $tables = CustomTable::allRecords();

            $table = collect($tables)->first(function ($table) use ($obj) {
                if (is_numeric($obj)) {
                    return array_get($table, 'id') == $obj;
                }
                return array_get($table, 'table_name') == $obj;
            });
            $suuid = array_get($table, 'suuid');
        }

        if (!isset($suuid)) {
            return null;
        }

        $namespace = namespace_join("Exceedone", "Exment", "Model");
        $className = "Class_{$suuid}";
        $fillpath = namespace_join($namespace, $className);

        // if the model doesn't defined, and $get_name_only is false
        // create class dynamically.
        if (!$get_name_only && !class_exists($fillpath)) {
            if (!isset($suuid)) {
                return null;
            }
            // get table. this block isn't called by createCustomTableTrait
            $table = CustomTable::findBySuuid($suuid);
            if (!is_null($table)) {
                $table->createTable();
                ClassBuilder::createCustomValue($namespace, $className, $fillpath, $table, $obj);
            }
        }

        return "\\".$fillpath;
    }
}

if (!function_exists('canConnection')) {
    /**
     * whether database canConnection
     * @return bool
     */
    function canConnection()
    {
        return System::requestSession(Define::SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE, function () {
            // get all table names
            return DB::canConnection();
        });
    }
}

if (!function_exists('hasTable')) {
    /**
     * whether database has table
     * @param string $table_name *only table name
     * @return string
     */
    function hasTable($table_name)
    {
        $tables = System::requestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, function () {
            // get all table names
            return DB::connection()->getDoctrineSchemaManager()->listTableNames();
        });

        return in_array($table_name, $tables);
    }
}

if (!function_exists('hasColumn')) {
    /**
     * whether database has column using table
     * @param string $table_name *only table name string. not object
     * @param string $column_name *only column name string. not object
     * @return string
     */
    function hasColumn($table_name, $column_name)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE, $table_name);
        $columns = System::requestSession($key, function () use ($table_name) {
            // get all table names
            return DB::connection()->getSchemaBuilder()->getColumnListing($table_name);
        });

        return in_array($column_name, $columns);
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

if (!function_exists('getCurrencySymbolLabel')) {
    /**
     * Get Currency Sybmol. ex. $, ￥, ...
     */
    function getCurrencySymbolLabel($currencySymbol, $html = false, $value = null)
    {
        $currencySymbol = CurrencySymbol::getEnum($currencySymbol);
        if (is_null($currencySymbol)) {
            return $value;
        }

        $currencyOption = $currencySymbol->getOption();
        
        $symbol = $html ? array_get($currencyOption, 'html') : array_get($currencyOption, 'text');

        if (isset($currencyOption)) {
            if (array_get($currencyOption, 'type') == 'before') {
                $text = "$symbol$value";
            } else {
                $text = "$value$symbol";
            }
            return $text;
        }
        return null;
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
            ],
            $options
        );

        try {
            // check string
            preg_match_all('/'.Define::RULES_REGEX_VALUE_FORMAT.'/', $format, $matches);
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $str = null;
                    $matchString = null;
                    try {
                        $match = $matches[1][$i];
                        $matchString = $matches[0][$i];
                        
                        //split semi-coron
                        $length_array = explode("/", $match);
                        $matchOptions = [];
                        if (count($length_array) > 1) {
                            $targetFormat = $length_array[0];
                            // $item is splited comma, key=value string
                            foreach (explode(',', $length_array[1]) as $item) {
                                $kv = explode('=', $item);
                                if (count($kv) <= 1) {
                                    continue;
                                }
                                $matchOptions[$kv[0]] = $kv[1];
                            }
                        } else {
                            $targetFormat = $length_array[0];
                        }

                        //$targetFormat = strtolower($targetFormat);
                        // get length
                        $length_array = explode(":", $targetFormat);
                        $key = $length_array[0];
                        
                        $systemValues = collect(SystemColumn::getOptions())->pluck('name')->toArray();
                        // define date array
                        $dateStrings = [
                            'ymdhms' => 'YmdHis',
                            'ymdhm' => 'YmdHi',
                            'ymdh' => 'YmdH',
                            'ymd' => 'Ymd',
                            'ym' => 'Ym',
                            'hms' => 'His',
                            'hm' => 'Hi',

                            'ymdhis' => 'YmdHis',
                            'ymdhi' => 'YmdHi',
                            'his' => 'His',
                            'hi' => 'Hi',
                        ];
                        $dateValues = [
                            'year' => 'year',
                            'month' => 'month',
                            'day' => 'day',
                            'hour' => 'hour',
                            'minute' => 'minute',
                            'second' => 'second',
                            'y' => 'year',
                            'm' => 'month',
                            'd' => 'day',
                            'h' => 'hour',
                            'i' => 'minute',
                            's' => 'second',
                        ];

                        $callbacked = false;
                        if (array_key_value_exists('matchBeforeCallback', $options)) {
                            // execute callback
                            $callbackFunc = $options['matchBeforeCallback'];
                            $result = $callbackFunc($length_array, $match, $format, $custom_value, $options);
                            if ($result) {
                                $str = $result;
                                $callbacked = true;
                            }
                        }

                        if (!$callbacked) {
                            ///// System value
                            if (in_array($key, $systemValues)) {
                                if (!isset($custom_value)) {
                                    $str = '';
                                }
                                //else, get system value
                                else {
                                    $str = $custom_value->{$key};
                                    if (count($length_array) > 1) {
                                        $str = sprintf('%0'.$length_array[1].'d', $str);
                                    }
                                }
                            }
                            ///// value_url
                            elseif ($key == "value_url") {
                                if (!isset($custom_value)) {
                                    $str = '';
                                }

                                //else, getting url
                                else {
                                    $tag = array_key_value_exists('link', $matchOptions);
                                    $str = $custom_value->getUrl(['tag' => $tag, 'modal' => false]) ?? '';
                                    array_forget($matchOptions, 'link');
                                }
                            }
                            ///// value
                            ///// base_info
                            elseif (in_array($key, ["value", SystemTableName::BASEINFO])) {
                                if ($key == "value") {
                                    $target_value = $custom_value;
                                } else {
                                    $target_value = getModelName(SystemTableName::BASEINFO)::first();
                                }
                                if (!isset($target_value)) {
                                    $str = '';
                                }
                                // get value from model
                                elseif (count($length_array) <= 1) {
                                    $str = $target_value->getLabel();
                                } else {
                                    // get comma string from index 1.
                                    $length_array = array_slice($length_array, 1);

                                    $str = $target_value->getValue(implode(',', $length_array), true, $matchOptions) ?? '';
                                }
                            }
                            ///// sum
                            elseif ($key == "sum") {
                                if (!isset($custom_value)) {
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
                                        $sum += intval(str_replace(',', '', $child->getValue($length_array[2]) ?? 0));
                                    }
                                    $str = strval($sum);
                                }
                            }
                            ///// child
                            elseif ($key == "child") {
                                if (!isset($custom_value)) {
                                    $str = '';
                                }

                                // get sum value from children model
                                elseif (count($length_array) <= 3) {
                                    $str = '';
                                }
                                //else, getting value using cihldren
                                else {
                                    // get children values
                                    $children = $custom_value->getChildrenValues($length_array[1]) ?? [];
                                    // get length
                                    $index = intval($length_array[3]);
                                    // get value
                                    if (count($children) <= $index) {
                                        $str = '';
                                    } else {
                                        $str = $children[$index]->getValue($length_array[2], true, $matchOptions) ?? '';
                                    }
                                }
                            }
                            ///// parent
                            elseif ($key == 'parent') {
                                if (!isset($custom_value)) {
                                    $str = '';
                                }
                                // get value from model
                                elseif (count($length_array) <= 1) {
                                    $str = $custom_value->getParentValue(true);
                                } else {
                                    $parentModel = $custom_value->getParentValue(false);
                                    $str = $parentModel->getValue($length_array[1], true, $matchOptions) ?? '';
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
                            // system
                            elseif ($key == "system") {
                                if (count($length_array) < 2) {
                                    $str = '';
                                } else {
                                    $key_system = $length_array[1];
                                    if (System::hasFunction($key_system)) {
                                        $str = System::{$key_system}();
                                    }
                                    // get static value
                                    elseif ($key_system == "login_url") {
                                        $str = admin_url("auth/login");
                                    } elseif ($key_system == "system_url") {
                                        $str = admin_url("");
                                    }
                                }
                            }
                            // if has $datestrings, conbert using date string
                            elseif (array_key_exists(strtolower($key), $dateStrings)) {
                                $str = Carbon::now()->format($dateStrings[strtolower($key)]);
                            }
                            // if has $dateValues, conbert using date value
                            elseif (array_key_exists(strtolower($key), $dateValues)) {
                                $str = Carbon::now()->{$dateValues[strtolower($key)]};
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
                        }
                    } catch (\Exception $e) {
                        $str = '';
                    }

                    if (array_key_value_exists('link', $matchOptions)) {
                        $str = "<a href='$str'>$str</a>";
                    }

                    // replace
                    $format = str_replace($matchString, $str, $format);
                }
            }
        } catch (\Exception $e) {
        }

        if (array_key_value_exists('afterCallback', $options)) {
            // execute callback
            $callbackFunc = $options['afterCallback'];
            $format = $callbackFunc($format, $custom_value, $options);
        }
        return $format;
    }
}

// Database Difinition --------------------------------------------------
if (!function_exists('shouldPassThrough')) {
    function shouldPassThrough($initialize = false)
    {
        $request = app('request');
        
        if ($initialize) {
            $excepts = [
                admin_base_path('initialize'),
                admin_base_path('install'),
                admin_base_path('template/search'),
            ];
        } else {
            $excepts = [
                admin_base_path('auth/login'),
                admin_base_path('auth/logout'),
                admin_base_path('auth/reset'),
                admin_base_path('auth/forget'),
                admin_base_path('initialize'),
                admin_base_path('template/search'),
            ];
        }

        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('getTransArray')) {
    /**
     * Create Associative array translated
     */
    function getTransArray($array, $base_key, $isExment = true)
    {
        if ($array instanceof \MyCLabs\Enum\Enum) {
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

if (! function_exists('abortJson')) {
    /**
     * abort response as json.
     * *Have to return object.
     *
     * @param  \Symfony\Component\HttpFoundation\Response|int     $code
     * @param  string  $message
     * @param  array   $headers
     * @return void
     */
    function abortJson($code, $message = null)
    {
        if (!is_null($message) && is_string($message)) {
            return response()->json(['message' => $message], $code);
        }

        return response()->json($message, $code);
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

        return response()->json($results, $results['result'] === true ? 200 : 400);
    }
}

if (!function_exists('downloadFile')) {
    /**
     * download file.
     * Support large file
     */
    function downloadFile($path, $disk)
    {
        $driver = $disk->getDriver();
        $metaData = $driver->getMetadata($path);
        $stream = $driver->readStream($path);

        // get page name
        $name = rawurlencode(mb_basename($path));
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
            },
            200,
            [
                'Content-Type' => $metaData['type'],
                'Content-disposition' => "attachment; filename*=UTF-8''$name",
            ]
        );
    }
}


if (!function_exists('getExmentVersion')) {
    /**
     * getExmentVersion using session and composer
     *
     * @return array $latest: new version in package, $current: this version in server
     */
    function getExmentVersion($getFromComposer = true)
    {
        try {
            try {
                $version_json = app('request')->session()->get(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION);
            } catch (\Exception $e) {
            }
    
            if (isset($version_json)) {
                $version = json_decode($version_json, true);
                $latest = array_get($version, 'latest');
                $current = array_get($version, 'current');
            }
            
            if ((empty($latest) || empty($current))) {
                // get current version from composer.lock
                $composer_lock = base_path('composer.lock');
                if (!\File::exists($composer_lock)) {
                    return [null, null];
                }

                $contents = \File::get($composer_lock);
                $json = json_decode($contents, true);
                if (!$json) {
                    return [null, null];
                }
                
                // get exment info
                $packages = array_get($json, 'packages');
                $exment = collect($packages)->filter(function ($package) {
                    return array_get($package, 'name') == Define::COMPOSER_PACKAGE_NAME;
                })->first();
                if (!isset($exment)) {
                    return [null, null];
                }
                $current = array_get($exment, 'version');
                
                // if outside api is not permitted, return only current
                if (!System::outside_api() || !$getFromComposer) {
                    return [null, $current];
                }

                // if already executed
                if (session()->has(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE)) {
                    return [null, $current];
                }

                //// get latest version
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', Define::COMPOSER_VERSION_CHECK_URL, [
                    'http_errors' => false,
                ]);

                session([Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE => true]);

                $contents = $response->getBody()->getContents();
                if ($response->getStatusCode() != 200) {
                    return [null, null];
                }

                $json = json_decode($contents, true);
                if (!$json) {
                    return [null, null];
                }
                $packages = array_get($json, 'packages.'.Define::COMPOSER_PACKAGE_NAME);
                if (!$packages) {
                    return [null, null];
                }

                // sort by timestamp
                $sortedPackages = collect($packages)->sortByDesc('time');
                foreach ($sortedPackages as $key => $package) {
                    // if version is "dev-", continue
                    if (substr($key, 0, 4) == 'dev-') {
                        continue;
                    }
                    $latest = $key;
                    break;
                }
                
                try {
                    session()->put(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION, json_encode([
                        'latest' => $latest, 'current' => $current
                    ]));
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
            session([Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE => true]);
        }
        
        return [$latest ?? null, $current ?? null];
    }
}


if (!function_exists('getExmentCurrentVersion')) {
    /**
     * getExmentCurrentVersion
     *
     * @return string this version in server
     */
    function getExmentCurrentVersion()
    {
        return getExmentVersion(false)[1];
    }
}

if (!function_exists('checkLatestVersion')) {
    /**
     * check exment's next version
     *
     * @return array $latest: new version in package, $current: this version in server
     */
    function checkLatestVersion()
    {
        list($latest, $current) = getExmentVersion();
        $latest = trim($latest, 'v');
        $current = trim($current, 'v');
        
        if (empty($latest) || empty($current)) {
            return SystemVersion::ERROR;
        } elseif (strpos($current, 'dev-') === 0) {
            return SystemVersion::DEV;
        } elseif ($latest === $current) {
            return SystemVersion::LATEST;
            $message = exmtrans("system.version_latest");
            $icon = 'check-square';
            $bgColor = 'blue';
        } else {
            return SystemVersion::HAS_NEXT;
        }
    }
}

if (!function_exists('getPagerOptions')) {
    /**
     * get pager select options
     */
    function getPagerOptions($addEmpty = false, $counts = Define::PAGER_GRID_COUNTS)
    {
        $options = [];

        if ($addEmpty) {
            $options[0] = exmtrans("custom_view.pager_count_default");
        }
        foreach ($counts as $count) {
            $options[$count] = $count. ' ' . trans('admin.entries');
        }
        return $options;
    }
}

if (!function_exists('getTrueMark')) {
    /**
     * get true mark. If $val is true, output mark
     */
    function getTrueMark($val)
    {
        if (!boolval($val)) {
            return null;
        }

        return config('exment.true_mark', '<i class="fa fa-check"></i>');
    }
}

// Excel --------------------------------------------------
if (!function_exists('getDataFromSheet')) {
    /**
     * get Data from excel sheet
     */
    function getDataFromSheet($sheet, $skip_excel_row_no = 0, $keyvalue = false, $isGetMerge = false)
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
                $value = getCellValue($cell, $sheet, $isGetMerge);

                // if keyvalue, set array as key value
                if ($keyvalue) {
                    $key = getCellValue($column_no."1", $sheet, $isGetMerge);
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
    function getCellValue($cell, $sheet, $isGetMerge = false)
    {
        if (is_string($cell)) {
            $cell = $sheet->getCell($cell);
        }

        // if merge cell, get from master cell
        if ($isGetMerge && $cell->isInMergeRange()) {
            $mergeRange = $cell->getMergeRange();
            $cell = $sheet->getCell(explode(":", $mergeRange)[0]);
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

if (!function_exists('getCellAlphabet')) {
    /**
     */
    function getCellAlphabet($no)
    {
        $alphabet = "ZABCDEFGHIJKLMNOPQRSTUVWXY";
        $columnStr = '';
        $m = 0;
            
        do {
            $m = $no % 26;
            $columnStr = substr($alphabet, $m, 1) . $columnStr;
            $no = floor($no / 26);
        } while (0 < $no && $m != 0);
    
        return $columnStr;
    }
}

if (!function_exists('getUserName')) {
    /**
     * Get database user name.
     * @param string $id
     * @return string user name
     */
    function getUserName($id, $link = false)
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($id, true);
        if (!isset($user)) {
            return null;
        }
        if ($user->trashed()) {
            return exmtrans('common.trashed_user');
        }

        if ($link) {
            return $user->getUrl(true);
        }
        return $user->getLabel();
    }
}

if (!function_exists('useLoginProvider')) {
    /**
     * use login provider
     */
    function useLoginProvider()
    {
        $config = config('exment.login_providers');
        if (is_nullorempty($config)) {
            return false;
        } elseif (is_array($config)) {
            return (count($config) > 0);
        } else {
            return true;
        }
    }

    if (!function_exists('admin_exclusion_path')) {
        /**
         * Get admin exclusion url.
         *
         * @param string $path
         *
         * @return string
         */
        function admin_exclusion_path($path = '')
        {
            $path = trim($path, '/');

            $prefix = trim(config('admin.route.prefix'), '/');

            if (starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }

            $path = trim($path, '/');
    
            return $path?? '/';
        }
    }

    if (!function_exists('getCustomField')) {
        function getCustomField($data, $field_label = null)
        {
            $view_column_target = array_get($data, 'view_column_target');
            $view_filter_condition = array_get($data, 'view_filter_condition');

            if (!isset($view_column_target)) {
                return null;
            }
    
            $value_type = null;
    
            if (isset($view_filter_condition)) {
                $value_type = ViewColumnFilterOption::VIEW_COLUMN_VALUE_TYPE($view_filter_condition);
    
                if ($value_type == 'none') {
                    return null;
                }
            }
    
            // get column item
            $column_item = CustomViewFilter::getColumnItem($view_column_target);
            if (isset($field_label)) {
                $column_item->setLabel($field_label);
            }
    
            return $column_item->getFilterField($value_type);
        }
    }
}

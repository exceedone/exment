<?php

use Exceedone\Exment\Services\ClassBuilder;
use Exceedone\Exment\Services\ReplaceFormat\ReplaceFormatService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\ModelBase;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\CurrencySymbol;
use Exceedone\Exment\Enums\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webpatser\Uuid\Uuid;
use Carbon\Carbon;
use Mews\Purifier\Facades\Purifier;

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
        return \Exment::getManualUrl($uri);
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
        return htmlspecialchars_ex($str, ENT_QUOTES|ENT_HTML5);
    }
}

if (!function_exists('esc_script_tag')) {
    /**
     * escape only script tag
     *
     * @deprecated Please use html_clean
     */
    function esc_script_tag($html)
    {
        return html_clean($html);
    }
}

if (!function_exists('html_clean')) {
    /**
     * clean html with HTML Purifier
     */
    function html_clean($html)
    {
        if (is_nullorempty($html)) {
            return $html;
        }

        try {
            // default setting for exment
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', Define::HTML_ALLOWED_DEFAULT);
            $config->set('HTML.AllowedAttributes', Define::HTML_ALLOWED_ATTRIBUTES_DEFAULT);
            $config->set('CSS.AllowedProperties', Define::CSS_ALLOWED_PROPERTIES_DEFAULT);

            // override exment setting
            if (!is_null($c = config('exment.html_allowed'))) {
                $config->set('HTML.Allowed', $c);
            }
            if (!is_null($c = config('exment.html_allowed_attributes'))) {
                $config->set('HTML.AllowedAttributes', $c);
            }
            if (!is_null($c = config('exment.css_allowed_properties'))) {
                $config->set('CSS.AllowedProperties', $c);
            }

            return Purifier::clean($html, $config);
        } catch (\Exception $ex) {
            return null;
        }
    }
}

if (!function_exists('is_nullorempty')) {
    /**
     * validate string, array, Collection and object.
     *
     * @return bool null is true, "" is true, 0 and "0" is false.
     */
    function is_nullorempty($obj)
    {
        if (is_null($obj)) {
            return true;
        }
        if (is_string($obj) && strlen_ex($obj) == 0) {
            return true;
        }
        if (is_array($obj) && count($obj) == 0) {
            return true;
        }
        if ($obj instanceof \Illuminate\Support\Collection && $obj->count() == 0) {
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
     *
     * @param mixed $str
     * @return string|null
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


if (!function_exists('floorDigit')) {
    /**
     * Truncate to decimal $digit digit.
     *
     * @param int|double|null $num
     * @param int $digit Decimal digits
     * @param bool $display Whether to set 0 if the number of decimal places is less than the specified number of digits
     * @return double|int|string
     */
    function floorDigit($num, int $digit, bool $display = false)
    {
        if ($digit < 0) {
            $digit = 0;
        }
        $numPointPosition = intval(strpos($num, '.'));

        // if for display
        $result = null;
        if ($numPointPosition === 0) { //$num is an integer
            $result = $num;
        } else {
            $result = floatval(substr($num, 0, $numPointPosition + $digit + 1));
            ;
        }

        if ($display && $digit > 0) {
            $result = sprintf("%.{$digit}f", $result);
        }

        return $result;
    }
}

if (!function_exists('hex2rgb')) {
    function hex2rgb($hex)
    {
        if (substr($hex, 0, 1) == "#") {
            $hex = substr($hex, 1) ;
        }
        if (strlen_ex($hex) == 3) {
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
if (!function_exists('trydecrypt')) {
    /**
     * decrypt if can, caanot return null
     */
    function trydecrypt($value)
    {
        try {
            return isset($value) ? decrypt($value) : null;
        } catch (\Exception $ex) {
            return null;
        }
    }
}

// File, path  --------------------------------------------------
if (!function_exists('exment_app_path')) {

    /**
     * Get Application exment path.
     *
     * @param string $path
     *
     * @return string
     */
    function exment_app_path($path = '')
    {
        return ucfirst(config('exment.directory', app_path('Exment'))).($path ? DIRECTORY_SEPARATOR.$path : $path);
    }
}

if (!function_exists('exment_package_path')) {

    /**
     * Get package exment path.
     *
     * @param string $path
     *
     * @return string
     */
    function exment_package_path($path = '')
    {
        $reflection = new \ReflectionClass(\Exceedone\Exment\ExmentServiceProvider::class);
        $package_path = dirname(dirname($reflection->getFileName()));

        return path_join($package_path, $path);
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

if (!function_exists('admin_urls_query')) {
    /**
     * Join admin url paths and query. Please set last arg
     */
    function admin_urls_query(...$pass_array)
    {
        // get last arg
        $args = func_get_args();
        $count = count($args);
        if (count($args) <= 1) {
            return admin_urls($args);
        }

        $args = collect($args);
        $query = $args->last();

        $url = admin_urls(...$args->slice(0, $count - 1)->toArray());
        return $url . '?' . http_build_query($query);
    }
}
if (!function_exists('asset_urls')) {
    /**
     * Join admin url paths.
     */
    function asset_urls(...$pass_array)
    {
        return asset(url_join(...$pass_array));
    }
}

if (!function_exists('assets_query')) {
    /**
     * Join url paths and query. Please set last arg
     */
    function assets_query(...$pass_array)
    {
        // get last arg
        $args = func_get_args();
        $count = count($args);
        if (count($args) <= 1) {
            return asset(url_join($args));
        }

        $args = collect($args);
        $query = $args->last();

        $url = asset(url_join(...$args->slice(0, $count - 1)->toArray()));
        return $url . '?' . http_build_query($query);
    }
}

if (!function_exists('public_form_base_path')) {
    /**
     * Join public form base path.
     */
    function public_form_base_path()
    {
        return config('exment.publicform_route_prefix', 'publicform');
    }
}

if (!function_exists('public_form_url')) {
    /**
     * Join public form urls.
     * "Cannot join path, because when you want to use public form, you have to set public form's uuid."
     */
    function public_form_url()
    {
        return asset(public_form_base_path());
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
        return join_paths('/', $pass_array);
        //return join_paths(DIRECTORY_SEPARATOR, $pass_array);
    }
}

if (!function_exists('path_join_os')) {
    /**
     * Join FilePath. consider OS SEPARATOR.
     */
    function path_join_os(...$pass_array)
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

if (!function_exists('path_ltrim')) {
    /**
     * ltrim FilePath.
     *
     * @param string $path target file path.
     * @param string $ltrim removing path.
     * If contains ex. "20210312123244/Plugins/Plugin.php" and want to get "Plugins/Plugin.php", set "20210312123244".
     * @return string
     */
    function path_ltrim($path, $ltrim)
    {
        foreach (['/', '\\'] as $split) {
            $l = str_replace($split, '/', $ltrim);

            if (is_nullorempty($l)) {
                $l = $split;
            }

            if (mb_strpos($path, $l) !== 0) {
                continue;
            }

            $path = mb_substr($path, mb_strlen($ltrim));

            $path = ltrim($path, '/');
            $path = ltrim($path, '\\');
        }

        return $path;
    }
}

if (!function_exists('path_rtrim')) {
    /**
     * rtrim FilePath.
     *
     * @param string $path target file path.
     * @param string $rtrim removing path.
     * If contains ex. "20210312123244/Plugins/Plugin.php" and want to get "20210312123244/Plugins", set "Plugin.php".
     * @return string
     */
    function path_rtrim($path, $rtrim)
    {
        foreach (['/', '\\'] as $split) {
            $l = str_replace($split, '/', $rtrim);

            if (is_nullorempty($l)) {
                $l = $split;
            }

            // check mb_strpos from back.
            $mb_strpos = mb_strpos($path, $l);
            $mb_strlen = mb_strlen($path) - mb_strlen($l);
            if ($mb_strpos !== $mb_strlen) {
                continue;
            }

            // mb_substr from back.
            $path = mb_substr($path, 0, $mb_strlen);

            $path = rtrim($path, '/');
            $path = rtrim($path, '\\');
        }

        return $path;
    }
}

if (!function_exists('getFullpath')) {
    function getFullpath($filename, $disk, $mkdir = false)
    {
        if (is_string($disk)) {
            $disk = Storage::disk($disk);
        }

        $adapter = $disk->getAdapter();
        $path = \Exment::getPathPrefix($adapter, $filename);

        if ($mkdir) {
            $dirPath = pathinfo($path)['dirname'];
            \Exment::makeDirectory($dirPath);
        }
        return $path;
    }
}


if (!function_exists('mb_basename')) {
    function mb_basename($str, $suffix=null)
    {
        $tmp = preg_split('/[\/\\\\]/', $str);
        $res = end($tmp);
        if (strlen_ex($suffix)) {
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


if (!function_exists('isMatchRequest')) {
    /**
     * Is match uri from request
     *
     * @param array|string $uris
     * @return boolean
     */
    function isMatchRequest($uris = null)
    {
        $request = app('request');

        foreach (toArray($uris) as $uri) {
            $uri = admin_base_path($uri);

            if ($uri !== '/') {
                $uri = trim($uri, '/');
            }

            if ($request->is($uri)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('deleteDirectory')) {
    /**
     * delete target directory
     */
    function deleteDirectory($disk, $path)
    {
        if (is_nullorempty($path)) {
            return;
        }
        if (is_string($disk)) {
            $disk = \Storage::disk($disk);
        }

        try {
            $directories = $disk->directories($path);
            foreach ($directories as $directory) {
                deleteDirectory($disk, $directory);
            }

            $disk->delete($disk->files($path));
            $disk->deleteDirectory($path);
        } catch (\Exception $ex) {
        }
    }
}

// date --------------------------------------------------
if (!function_exists('hasDuplicateDate')) {
    /**
     * Check dates Duplicate
     *
     * @param array $dates array of between ['start':Carbon, 'end':Carbon]
     * @return boolean Duplicate:true
     */
    function hasDuplicateDate($dates)
    {
        $dates = collect($dates);

        for ($i = 0; $i < count($dates) - 1; $i++) {
            $date = $dates->values()->get($i);
            $searchDates = $dates->slice($i + 1);

            if ($searchDates->contains(function ($searchDate) use ($date) {
                return $date['start']->lte($searchDate['end']) && $date['end']->gte($searchDate['start']);
            })) {
                return true;
            }
        }

        return false;
    }
}

// array --------------------------------------------------
if (!function_exists('array_boolval')) {
    /**
     * array_boolval
     * get array_get and return boolval
     * @return bool
     */
    function array_boolval($array, $key, $default = false): bool
    {
        if (is_string($array)) {
            $array = [$array];
        }
        $value = array_get($array, $key, $default);
        return boolval($value);
    }
}

if (!function_exists('array_keys_exists')) {
    /**
     * array_keys_exists
     * $keys contains $array, return true.
     * @param mixed $keys
     * @param mixed $array
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
     * @param mixed $key
     * @param mixed $array
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
            if (!is_nullorempty(array_get($array, $k))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_value_exists')) {

    /**
     * whether has array_value
     *
     * @param mixed $value
     * @param mixed $array
     * @return bool
     */
    function array_value_exists($value, $array): bool
    {
        if (is_null($array)) {
            return false;
        }
        foreach ($array as $arr) {
            if (isMatchString($arr, $value)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('array_dot_reverse')) {
    /**
     * convert dotted_array to array
     *
     * @param $array
     * @return array|null
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

if (!function_exists('array_remove')) {
    /**
     * array remove as "array_forget"
     */
    function array_remove(array $array, $keys)
    {
        $result = array_diff($array, toArray($keys));
        //move index
        return array_values($result);
    }
}

if (!function_exists('jsonToArray')) {
    /**
     * json to array
     *
     * @param mixed $value
     * @return mixed
     */
    function jsonToArray($value)
    {
        if (!isset($value)) {
            return [];
        }

        // convert json to array
        if (is_array($value)) {
            return $value;
        }
        // convert json to array
        /** @phpstan-ignore-next-line Call to function is_array() with mixed will always evaluate to false. already checked is_array() */
        if (!is_array($value) && is_json($value)) {
            return json_decode_ex($value, true);
        }
        return $value;
    }
}

if (!function_exists('stringToArray')) {
    /**
     * string(as comma): to array
     * Collection : $collect->toArray()
     *
     * @param mixed $value
     * @return array
     */
    function stringToArray($value)
    {
        if (is_nullorempty($value)) {
            return [];
        }

        // convert json to array
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->toArray();
        }

        $array = explode_ex(',', $value);

        return collect($array)->map(function ($a) {
            return trim($a);
        })->toArray();
    }
}

if (!function_exists('breakCommaToArray')) {
    /**
     * string(as Line feed code and comma): to array
     * Collection : $collect->toArray()
     *
     * @param mixed $value
     * @return array
     */
    function breakCommaToArray($value)
    {
        if (is_nullorempty($value)) {
            return [];
        }

        // convert json to array
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->toArray();
        }

        $value = str_replace(array("\r\n", "\r", "\n", ","), "\n", $value);
        $array = explode("\n", $value);

        return collect($array)->map(function ($a) {
            return trim($a);
        })->toArray();
    }
}

if (!function_exists('toArray')) {
    /**
     * Convert array. Such as casting array
     * string : casting array
     * Collection : $collect->toArray()
     *
     * @param mixed $value
     * @return ?array
     */
    function toArray($value): ?array
    {
        if (is_null($value)) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->all();
        }

        //TODO: I think this should not call $model->toArray()...
        if ($value instanceof \Illuminate\Database\Eloquent\Model) {
            return $value->toArray();
        }

        return (array)$value;
    }
}

if (!function_exists('arrayToString')) {
    /**
     * array to string(comma) string
     *
     * @param $value
     * @return string|null
     */
    function arrayToString($value)
    {
        if (is_null($value)) {
            return null;
        }

        // convert json to array
        if (is_array($value)) {
            return implode(',', $value);
        }
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->implode(',');
        }

        return (string)$value;
    }
}

if (!function_exists('is_json')) {
    function is_json($string)
    {
        return is_string($string) && is_array(json_decode_ex($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }
}


if (!function_exists('is_vector')) {
    /**
     * whether array is vector array(not associative array)
     *
     * @param array $arr checking array
     * @return boolean
     * true: [0, 1, 2]
     * false: ['foo' => 0, 'bar' => 1]
     * false: [0 => 'foo', 1 => 'bar']
     */
    function is_vector(array $arr)
    {
        // foreach($arr as $key => $value){
        //     if($key !== $value){
        //         return false;
        //     }
        // }

        // return true;

        return array_values($arr) === $arr;
    }
}

if (!function_exists('is_list')) {
    /**
     * is value is array or Collection
     *
     * @param mixed $value
     * @return bool
     */
    function is_list($value): bool
    {
        if (is_null($value)) {
            return false;
        }

        return is_array($value) || $value instanceof \Illuminate\Support\Collection;
    }
}

if (!function_exists('isMatchString')) {
    /**
     * isMatch string using strcmp
     *
     * @param mixed $v1
     * @param mixed $v2
     * @return bool
     */
    function isMatchString($v1, $v2): bool
    {
        if (is_array($v1) || is_array($v2)) {
            return false;
        }
        return strcmp_ex($v1, $v2) == 0;
    }
}

if (!function_exists('isMatchDecimal')) {
    /**
     * compare decimal number
     *
     * @param mixed $v1
     * @param mixed $v2
     * @return bool
     */
    function isMatchDecimal($v1, $v2): bool
    {
        $v1 = rtrim((strpos($v1, ".") !== false ? rtrim($v1, "0") : $v1), ".");
        $v2 = rtrim((strpos($v2, ".") !== false ? rtrim($v2, "0") : $v2), ".");
        return strcmp_ex($v1, $v2) == 0;
    }
}

if (!function_exists('isMatchArray')) {
    /**
     * compare array
     *
     * @param array $v1
     * @param array $v2
     * @return bool
     */
    function isMatchArray(array $v1, array $v2): bool
    {
        if (count($v1) == count($v2)) {
            $dup = array_intersect($v1, $v2);
            return count($v1) == count($dup);
        }
        return false;
    }
}

// string --------------------------------------------------
if (!function_exists('rstrpos')) {
    /**
     * last of strpos. find from last, and return last offset.
     *
     * @param string $haystack
     * @param string $needle
     * @param integer $offset
     * @return int|false
     */
    function rstrpos(?string $haystack, ?string $needle, ?int $offset = 0)
    {
        $haystack = $haystack?? '';
        $needle = $needle?? '';

        $result = strrpos($haystack, $needle, $offset);
        if ($result === false) {
            return $result;
        }

        $strlen = strlen($haystack);
        return $result - $strlen + 1;
    }
}

if (!function_exists('make_password')) {
    function make_password($length = 16, $options = [])
    {
        $options = array_merge(
            [
                'alphabet_upper' => true,
                'alphabet_lower' => true,
                'number' => true,
                'mark' => true,
            ],
            $options
        );

        $chars = '';
        if ($options['alphabet_upper']) {
            $chars .= 'ABCDEFGHJKMNPQRSTUVWXYZ';
        }
        if ($options['alphabet_lower']) {
            $chars .= 'abcdefghjkmnpqrstuvwxyz';
        }
        if ($options['number']) {
            $chars .= '23456789';
        }
        if ($options['mark']) {
            $chars .= '!$#%_-';
        }
        $str = '';
        for ($i = 0; $i < $length; ++$i) {
            $str .= $chars[mt_rand(0, strlen_ex($chars) -1)];
        }
        return $str;
    }
}

if (!function_exists('make_randomstr')) {
    function make_randomstr($length, bool $useAlphabet = true, bool $useNumber = true)
    {
        $chars = '';
        if ($useAlphabet) {
            $chars .= "abcdefghjkmnpqrstuvwxyz";
        }
        if ($useNumber) {
            $chars .= "23456789";
        }

        $str = '';
        for ($i = 0; $i < $length; ++$i) {
            $str .= $chars[mt_rand(0, strlen_ex($chars) -1)];
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

if (!function_exists('is_uuid')) {
    function is_uuid($uuid): bool
    {
        return Uuid::validate($uuid);
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
     * @return array
     */
    function get_password_rule($required = true, ?LoginUser $login_user = null)
    {
        return \Exment::get_password_rule($required, $login_user);
    }
}

if (!function_exists('get_omitted_string')) {
    /**
     * if over string length. remove text, add "..."
     *
     * @param $text
     * @param $length
     * @return mixed|string|null
     */
    function get_omitted_string($text, $length = null)
    {
        if (is_null($length)) {
            $length = config('exment.grid_mat_length', Define::GRID_MAX_LENGTH);
        }

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
        return preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", $isescape ? esc_html($text) : $text);
    }
}

if (!function_exists('replaceBreakEsc')) {
    /**
     * replace and new line code to <br />
     * @return string
     */
    function replaceBreakEsc($text)
    {
        return preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", esc_html($text));
    }
}

if (!function_exists('replaceBrTag')) {
    /**
     * replace <br /> to new line code
     * @return string
     */
    function replaceBrTag($text)
    {
        return preg_replace("/<br *\/>/u", "\n", $text);
    }
}

if (!function_exists('explodeBreak')) {
    /**
     * explode new line code
     * @return array
     */
    function explodeBreak($text)
    {
        return explode_ex("\r\n", preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "\r\n", $text));
    }
}

if (!function_exists('getYesNo')) {
    /**
     * get yes no label
     * @return string
     */
    function getYesNo($value): string
    {
        return boolval($value) ? 'YES' : 'NO';
    }
}

// Laravel, laravel-admin --------------------------------------------------
if (!function_exists('getModelName')) {
    /**
     * Get custom_value's model fullpath.
     * this function contains flow creating eloquent class dynamically.
     *
     * @param $obj
     * @param $get_name_only
     * @return string|null
     */
    function getModelName($obj, $get_name_only = false)
    {
        if ($obj instanceof CustomValue) {
            $table = $obj->custom_table;
            $suuid = $table->suuid;
        } elseif ($obj instanceof CustomTable) {
            $suuid = $obj->suuid;
        } elseif ($obj instanceof CustomColumn) {
            $suuid = CustomTable::getEloquent($obj)->suuid;
        } elseif (is_numeric($obj) || is_string($obj)) {
            // get all table info
            $table = CustomTable::allRecordsCache(function ($table) use ($obj) {
                if (is_numeric($obj)) {
                    return array_get($table, 'id') == $obj;
                }
                return array_get($table, 'table_name') == $obj;
            })->first();

            // $table = collect($tables)->first(function ($table) use ($obj) {
            //     if (is_numeric($obj)) {
            //         return array_get($table, 'id') == $obj;
            //     }
            //     return array_get($table, 'table_name') == $obj;
            // });
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
        // Use session. Not request session
        return System::cache(Define::SYSTEM_KEY_SESSION_CAN_CONNECTION_DATABASE, function () {
            // get all table names
            return \ExmentDB::canConnection();
        }, true);
    }
}

if (!function_exists('hasTable')) {
    /**
     * whether database has table
     * *CANNOT USE if create table dynamic (ex. install)
     * @param string $table_name *only table name
     * @return bool
     */
    function hasTable($table_name)
    {
        $tables = System::cache(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES, function () {
            // get all table names
            return DB::connection()->getDoctrineSchemaManager()->listTableNames();
        }, true);

        return in_array($table_name, $tables);
    }
}

if (!function_exists('hasColumn')) {
    /**
     * whether database has column using table
     *
     * @param string $table_name *only table name string. not object
     * @param string $column_name *only column name string. not object
     * @return bool
     */
    function hasColumn($table_name, $column_name)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE, $table_name);
        $columns = System::cache($key, function () use ($table_name) {
            // get all table names
            return DB::connection()->getSchemaBuilder()->getColumnListing($table_name);
        }, true);

        return in_array($column_name, $columns);
    }
}

if (!function_exists('getDBTableName')) {
    /**
     * Get database table name.
     *
     * @param string|CustomTable|array $obj
     * @param bool $isThrow if true and not has database, throwing
     * @return string
     * @throws Exception
     */
    function getDBTableName($obj, $isThrow = true)
    {
        $obj = CustomTable::getEloquent($obj);
        if (!isset($obj) && $isThrow) {
            throw new Exception('table name is not found. please tell system administrator.');
        }
        return 'exm__'.array_get($obj, 'suuid');
    }
}

if (!function_exists('getEndpointName')) {
    /**
     * get endpoint name.
     *
     * @param mixed $obj
     * @return string|null
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
     *
     * @deprecated Please use ReplaceFormatService::replaceTextFromFormat
     */
    function replaceTextFromFormat($format, $custom_value = null, $options = [])
    {
        return ReplaceFormatService::replaceTextFromFormat($format, $custom_value, $options);
    }
}

// Database Difinition --------------------------------------------------
if (!function_exists('shouldPassThrough')) {
    function shouldPassThrough($initialize = false)
    {
        if ($initialize) {
            $excepts = [
                'initialize',
                'install',
                'template/search',
            ];
        } else {
            $excepts = [
                'auth/login',
                'auth/logout',
                'saml/login',
                'saml/logout',
                'auth/reset',
                'auth/forget',
                'initialize',
                'template/search',
            ];
        }

        return isMatchRequest($excepts);
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
     * @param  string|array|ErrorCode  $message
     * @param  ErrorCode  $errorCode
     * @return \Symfony\Component\HttpFoundation\Response
     */
    function abortJson($code, $message = null, $errorCode = null)
    {
        $result = [];
        if (!is_null($message)) {
            if (is_string($message)) {
                $result['message'] = $message;
            } elseif ($message instanceof ErrorCode) {
                $result['code'] = $message->getValue();
                $result['message'] = $message->getMessage();
            } elseif (is_array($message)) {
                $result = $message;
            }
        }

        if (!is_null($errorCode)) {
            $result['code'] = $errorCode->getValue();
        }

        return response()->json($result, $code);
    }
}

if (!function_exists('getAjaxResponse')) {
    /**
     * get ajax response.
     * using plugin, copy, data import, etc...
     *
     * @return \Symfony\Component\HttpFoundation\Response Response for ajax json
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
            'swal' => null,
            'swaltext' => null,
            'errors' => [],
            'pjaxmodal' => false,

            // response as file download
            'fileBase64' => null,
            'fileContentType' => null,
            'fileName' => null,
        ], $results);

        if (isset($results['swaltext']) && !isset($results['swal'])) {
            $results['swal'] = $results['result'] === true ? exmtrans('common.success') : exmtrans('common.error');
        }

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
        $type = $driver->mimeType($path);
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
                'Content-Type' => $type,
                'Content-disposition' => "attachment; filename*=UTF-8''$name",
            ]
        );
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


// Excel --------------------------------------------------
if (!function_exists('getDataFromSheet')) {
    /**
     * get Data from excel sheet
     */
    function getDataFromSheet($sheet, $keyvalue = false, $isGetMerge = false)
    {
        return \Exment::getDataFromSheet($sheet, $keyvalue, $isGetMerge);
    }
}

if (!function_exists('getCellValue')) {
    /**
     * get cell value
     */
    function getCellValue($cell, $sheet, $isGetMerge = false)
    {
        return \Exment::getCellValue($cell, $sheet, $isGetMerge);
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
     *
     * @param string|CustomValue $id
     * @param $link
     * @param $addAvatar
     * @return array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Translation\Translator|mixed|string|null
     */
    function getUserName($id, $link = false, $addAvatar = false)
    {
        if (is_nullorempty($id)) {
            return null;
        }

        if ($id instanceof CustomValue) {
            $user = $id;
        } else {
            $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel($id, true);
        }

        if (!isset($user)) {
            if (CustomTable::getEloquent(SystemTableName::USER)->hasCustomValueInDB($id)) {
                return exmtrans('common.message.no_permission');
            }
            return null;
        }
        if ($user->trashed()) {
            return exmtrans('common.trashed_user');
        }

        if ($link) {
            return $user->getUrl([
                'tag' => true,
                'add_avatar' => $addAvatar,
            ]);
        }
        return $user->getLabel();
    }
}

if (!function_exists('admin_exclusion_path')) {
    /**
     * Get admin exclusion url.
     * Ex. admin/data/testtable to data/testtable
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
            $path = substr($path, strlen_ex($prefix));
        }

        return trim($path, '/');
    }

    if (!function_exists('unicode_decode')) {

        /**
         * Get admin exclusion url.
         * Ex. admin/data/testtable to data/testtable
         *
         * @param string $str
         * @return string|null
         */
        function unicode_decode($str)
        {
            return preg_replace_callback("/\\\\u([0-9a-zA-Z]{4})/", function ($matches) {
                $char = mb_convert_encoding(pack("H*", $matches[1]), "UTF-8", "UTF-16");
                return $char;
            }, $str);
        }
    }

    if (!function_exists('unicode_encode')) {
        /**
         * Get admin exclusion url.
         * Ex. admin/data/testtable to data/testtable
         *
         * @param string $str
         * @return string|null
         */
        function unicode_encode($str)
        {
            return preg_replace_callback("/((?:[^\x09\x0A\x0D\x20-\x7E]{3})+)/", function ($matches) {
                $char = mb_convert_encoding($matches[1], "UTF-16", "UTF-8");
                $escaped = "";
                for ($i = 0, $l = strlen_ex($char); $i < $l; $i += 2) {
                    $escaped .=  "\u" . sprintf("%02x%02x", ord($char[$i]), ord($char[$i+1]));
                }
                return $escaped;
            }, $str);
        }
    }

    if (!function_exists('json_decode_ex')) {
        /**
         * Wrapper for json_decode that throws when an error occurs.
         *
         * @param array|string|null $json    JSON data to parse
         * @param bool   $assoc   When true, returned objects will be converted
         *                        into associative arrays.
         * @param int    $depth   User specified recursion depth.
         * @param int    $options Bitmask of JSON decode options.
         *
         * @return object|array|string|int|float|bool|null
         */
        function json_decode_ex($json, bool $assoc = false, int $depth = 512, int $options = 0)
        {
            if (is_null($json) || is_array($json)) {
                return $json;
            }

            return json_decode($json, $assoc, $depth, $options);
        }
    }
}

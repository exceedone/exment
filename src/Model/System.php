<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Config;
use Exceedone\Exment\Model\File as ExmentFile;
use Carbon\Carbon;
use Storage;
use Cache;

class System extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    protected $casts = ['role' => 'json'];
    protected $primaryKey = 'system_name';
    public $incrementing = false;
    protected static $requestSession = [];

    public static function __callStatic($name, $argments)
    {
        // Get system setting value
        if (static::hasFunction($name)) {
            $setting = Define::SYSTEM_SETTING_NAME_VALUE[$name];
            return static::getset_system_value($name, $setting, $argments);
        }

        return parent::__callStatic($name, $argments);
    }

    /**
     * Get request session. This value avaibables only one request.
     *
     * @param string $key key name.
     * @param mixed $value setting value.
     * @return void
     */
    public static function requestSession($config_key, $value = null)
    {
        
        if (is_null($value)) {
            // check array_has
            if (array_has(static::$requestSession, $config_key)) {
                return static::$requestSession[$config_key];
            }

            return null;

        } elseif ($value instanceof \Closure) {
            // check array_has
            if (array_has(static::$requestSession, $config_key)) {
                return static::$requestSession[$config_key];
            }

            $val = $value();
            static::setRequestSession($config_key, $val);
            return $val;
        }
        static::setRequestSession($config_key, $value);
    }

    protected static function setRequestSession($key, $value){
        static::$requestSession[$key] = $value;
    }

    /**
     * reset all request settion
     */
    public static function resetRequestSession($key = null)
    {
        if(!isset($key)){
            static::$requestSession = [];
        }else{
            array_forget(static::$requestSession, $key);
        }
    }

    /**
     * Get and set from cache. 
     *
     * @param string $key key name.
     * @param mixed $value setting value.
     * @return void
     */
    protected static function cache($config_key, $value = null)
    {
        if (is_null($value)) {
            // first, check request session
            if(!is_null($val = static::requestSession($config_key))){
                return $val;
            }

            if(Cache::has($config_key)){
                $val = Cache::get($config_key);
                static::setRequestSession($config_key, $val);
                return $val;
            }

            return null;
        } elseif ($value instanceof \Closure) {
            if(!is_null($val = static::requestSession($config_key))){
                return $val;
            }
            
            if(Cache::has($config_key)){
                $val = Cache::get($config_key);
                static::setRequestSession($config_key, $val);
                return $val;
            }

            // get value
            $val = $value();

            // set session
            Cache::put($config_key, $val, 10);
            static::setRequestSession($config_key, $val);
            return $val;
        }

        static::setRequestSession($config_key, $value);
        Cache::put($config_key, $value, 10);
    }

    /**
     * reset Cache
     */
    protected static function resetCache($key = null)
    {
        if(!isset($key)){
            Cache::flush();
        }else{
            Cache::forget($key);
        }
    }

    /**
     * whether System_function keyname
     */
    public static function hasFunction($name)
    {
        return array_key_exists($name, Define::SYSTEM_SETTING_NAME_VALUE);
    }

    public static function get_system_keys($group = null)
    {
        $keys = [];
        foreach (Define::SYSTEM_SETTING_NAME_VALUE as $k => $v) {
            if (isset($group)) {
                if (is_string($group)) {
                    $group = [$group];
                }
                if (in_array(array_get($v, 'group'), $group)) {
                    $keys[] = $k;
                }
            } else {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    /**
     * get "systems" table key-value array
     */
    public static function get_system_values($group = null)
    {
        $array = [];
        foreach (static::get_system_keys($group) as $k) {
            $array[$k] = static::{$k}();
        }
        
        return $array;
    }

    protected static function getset_system_value($name, $setting, $argments)
    {
        if (count($argments) > 0) {
            static::set_system_value($name, $setting, $argments[0]);
            return null;
        } else {
            return static::get_system_value($name, $setting);
        }
    }

    protected static function get_system_value($name, $setting)
    {
        $config_key = static::getConfigKey($name);
        return static::cache($config_key, function () use ($name, $setting) {
            $system = static::allRecords(function ($record) use ($name) {
                return $record->system_name == $name;
            }, false)->first();

            $type = array_get($setting, 'type');
            $value = null;
            
            // if has data, return setting value or default value
            if (isset($system)) {
                $value = $system->system_value;
            }
            // if don't has data, but has config value in Define, return value from config
            elseif (!is_null(array_get($setting, 'config'))) {
                $value = Config::get(array_get($setting, 'config'));

                // if password, return
                if ($type == 'password') {
                    return $value;
                }
            }
            // if don't has data, but has default value in Define, return default value
            elseif (!is_null(array_get($setting, 'default'))) {
                $value = array_get($setting, 'default');
            }
    
            if ($type == 'boolean') {
                $value = boolval($value);
            } elseif ($type == 'int') {
                $value = is_null($value) ? null : intval($value);
            } elseif ($type == 'datetime') {
                $value = is_null($value) ? null : new Carbon($value);
            } elseif ($type == 'json') {
                $value = is_null($value) ? [] : json_decode($value);
            } elseif ($type == 'array') {
                $value = is_null($value) ? [] : explode(',', $value);
            } elseif ($type == 'file') {
                $value = is_null($value) ? null : Storage::disk(config('admin.upload.disk'))->url($value);
            } elseif ($type == 'password') {
                try {
                    $value = is_null($value) ? null : decrypt($value);
                } catch (\Exception $ex) {
                }
            }
            return $value;
        });
    }

    protected static function set_system_value($name, $setting, $value)
    {
        $system = static::allRecords(function ($record) use ($name) {
            return $record->system_name == $name;
        }, false)->first();

        if (!isset($system)) {
            $system = new System;
            $system->system_name = $name;
        }

        // change set value by type
        $type = array_get($setting, 'type');
        if ($type == 'int') {
            $system->system_value = is_null($value) ? null : intval($value);
        } elseif ($type == 'datetime') {
            if ($value instanceof Carbon) {
                $system->system_value = $value->toDateTimeString();
            } else {
                $system->system_value = is_null($value) ? null : $value;
            }
        } elseif ($type == 'json') {
            $system->system_value = is_null($value) ? null : json_encode($value);
        } elseif ($type == 'array') {
            $system->system_value = is_null($value) ? null : implode(',', $value);
        } elseif ($type == 'file') {
            $old_value = $system->system_value;
            if (!is_null($value)) {
                $move = array_get($setting, 'move');
                $exmentfile = ExmentFile::storeAs($value, $move, $value->getClientOriginalName());
                $system->system_value = $exmentfile->path;
            }

            // remove old file
            if (!is_null($value) && !is_null($old_value)) {
                Storage::disk(config('admin.upload.disk'))->delete($old_value);
            }
        } elseif ($type == 'password') {
            $system->system_value = is_null($value) ? null : encrypt($value);
        } else {
            $system->system_value = $value;
        }
        $system->saveOrFail();
        
        // update config
        $config_key = static::getConfigKey($name);
        static::cache($config_key, $system->system_value);

        return $system;
    }

    /**
     * destory value
     */
    public static function deleteValue($name)
    {
        $system = System::find($name);
        if (!isset($system)) {
            return;
        }
        $old_value = $system->system_value;
        
        // change set value by type
        $setting = Define::SYSTEM_SETTING_NAME_VALUE[$name];
        $type = array_get($setting, 'type');

        if ($type == 'file') {
            // remove old file
            if (!is_null($old_value)) {
                ExmentFile::deleteFileInfo($old_value);
            }
        }
        $system->system_value = null;
        $system->save();
        
        return $system;
    }

    protected static function getConfigKey($name)
    {
        return sprintf(Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, $name);
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::saved(function ($model) {
            static::resetAllRecordsCache();
        });
    }
}

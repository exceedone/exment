<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Config;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Carbon\Carbon;
use Storage;
use DB;


class System extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['role' => 'json'];
    protected $primaryKey = 'system_name';

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
     * whether System_function keyname
     */
    public static function hasFunction($name)
    {
        return array_key_exists($name, Define::SYSTEM_SETTING_NAME_VALUE);
    }

    public static function get_system_keys($group = null){
        $keys = [];
        foreach (Define::SYSTEM_SETTING_NAME_VALUE as $k => $v) {
            if(isset($group)){
                if(is_string($group)){
                    $group = [$group];
                }
                if(in_array(array_get($v, 'group'), $group)){
                    $keys[] = $k;
                }
            }else{
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

        // add system role --------------------------------------------------
        // get system role value
        $system_role = DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->where('morph_type', RoleType::SYSTEM)->get();
        // get Role list for system.
        $roles = Role::where('role_type', RoleType::SYSTEM)->get(['id', 'suuid', 'role_name']);
        foreach ($roles as $role) {
            foreach ([SystemTableName::USER, SystemTableName::ORGANIZATION] as $related_type) {
                // filter related_type and role_id. convert to string
                $filter = $system_role->filter(function ($value, $key) use ($role, $related_type) {
                    return $value->related_type  == $related_type && $value->role_id  == $role->id;
                });
                if (!isset($filter)) {
                    continue;
                }

                $array[$role->getRoleName($related_type)] = $filter->pluck('related_id')->map(function($value){
                    return strval($value);
                })->toArray();
            }
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
        if (!is_null(getRequestSession($config_key))) {
            return getRequestSession($config_key);
        }
        $system = System::find($name);
        $value = null;
        
        // if has data, return setting value or default value
        if (isset($system)) {
            $value = $system->system_value;
        }
        // if don't has data, but has default value in Define, return default value
        elseif (!is_null(array_get($setting, 'default'))) {
            $value = array_get($setting, 'default');
        }
        // if don't has data, but has config value in Define, return value from config
        elseif (!is_null(array_get($setting, 'config'))) {
            $value = Config::get(array_get($setting, 'config'));
        }

        $type = array_get($setting, 'type');
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
        }
        setRequestSession($config_key, $value);
        return $value;
    }

    protected static function set_system_value($name, $setting, $value)
    {
        $system = System::find($name);
        if (!isset($system)) {
            $system = new System;
            $system->system_name = $name;
        }

        // change set value by type
        $type = array_get($setting, 'type');
        if ($type == 'int') {
            $system->system_value = is_null($value) ? null : intval($value);
        }
        elseif ($type == 'datetime') {
            if($value instanceof Carbon){
                $system->system_value = $value->toDateTimeString();
            }else{
                $system->system_value = is_null($value) ? null : $value;    
            }
        }
        elseif ($type == 'json') {
            $system->system_value = is_null($value) ? null : json_encode($value);
        }
        elseif ($type == 'array') {
            $system->system_value = is_null($value) ? null : implode(',', $value);
        } 
        elseif ($type == 'file') {
            $old_value = $system->system_value;
            if (is_null($value)) {
                //TODO: how to check whether file is deleting by user.
                //$system->system_value = null;
            } else {
                $move = array_get($setting, 'move');
                $exmentfile = ExmentFile::storeAs($value, $move, $value->getClientOriginalName());
                $system->system_value = $exmentfile->path;
            }

            // remove old file
            if (!is_null($old_value)) {
                Storage::delete($old_value);
            }
        } else {
            $system->system_value = $value;
        }
        $system->saveOrFail();
        
        // update config
        $config_key = static::getConfigKey($name);
        setRequestSession($config_key, $system->system_value);
    }

    protected static function getConfigKey($name)
    {
        return "setting.$name";
    }
}

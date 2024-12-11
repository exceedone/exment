<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Facades\Config;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\File as ExmentFile;
use Carbon\Carbon;
use Storage;
use Cache;

/**
 * System definition.
 *
 * @property mixed $system_value
 * @method static boolean|void initialized($arg = null)
 * @method static string|void site_name($arg = null)
 * @method static string|void site_name_short($arg = null)
 * @method static string|void site_logo($arg = null)
 * @method static string|void site_logo_mini($arg = null)
 * @method static string|void site_favicon($arg = null)
 * @method static string|void site_skin($arg = null)
 * @method static string|void site_layout($arg = null)
 * @method static boolean|void api_available($arg = null)
 * @method static boolean|void outside_api($arg = null)
 * @method static boolean|void permission_available($arg = null)
 * @method static boolean|void organization_available($arg = null)
 * @method static string|void filter_search_type($arg = null)
 * @method static string|void system_mail_host($arg = null)
 * @method static string|void system_mail_port($arg = null)
 * @method static string|void system_mail_username($arg = null)
 * @method static string|void system_mail_password($arg = null)
 * @method static string|void system_mail_encryption($arg = null)
 * @method static string|void system_mail_from($arg = null)
 * @method static string|void system_mail_body_type($arg = null)
 * @method static string|void system_mail_from_view_name($arg = null)
 * @method static boolean|void userview_available($arg = null)
 * @method static boolean|void userdashboard_available($arg = null)
 * @method static string|void default_date_format($arg = null)
 * @method static int|void grid_pager_count($arg = null)
 * @method static int|void datalist_pager_count($arg = null)
 * @method static array|void grid_filter_disable_flg($arg = null)
 * @method static string|void system_values_pos($arg = null)
 * @method static string|void data_submit_redirect($arg = null)
 * @method static array|void header_user_info($arg = null)
 * @method static boolean|void complex_password($arg = null)
 * @method static int|void password_expiration_days($arg = null)
 * @method static boolean|void first_change_password($arg = null)
 * @method static int|void password_history_cnt($arg = null)
 * @method static string|void login_background_color($arg = null)
 * @method static string|void login_page_image($arg = null)
 * @method static string|void login_page_image_type($arg = null)
 * @method static string|void web_ip_filters($arg = null)
 * @method static string|void api_ip_filters($arg = null)
 * @method static int|void org_joined_type_role_group($arg = null)
 * @method static int|void org_joined_type_custom_value($arg = null)
 * @method static int|void filter_multi_user($arg = null)
 * @method static int|void custom_value_save_autoshare($arg = null)
 * @method static boolean|void backup_enable_automatic($arg = null)
 * @method static int|void backup_automatic_term($arg = null)
 * @method static int|void backup_automatic_hour($arg = null)
 * @method static array|void backup_target($arg = null)
 * @method static Carbon|void backup_automatic_executed($arg = null)
 * @method static int|void backup_history_files($arg = null)
 * @method static boolean|void login_use_2factor($arg = null)
 * @method static string|void login_2factor_provider($arg = null)
 * @method static array|void system_admin_users($arg = null)
 * @method static boolean|void show_default_login_provider($arg = null)
 * @method static boolean|void sso_redirect_force($arg = null)
 * @method static boolean|void sso_jit($arg = null)
 * @method static string|void sso_accept_mail_domain($arg = null)
 * @method static array|void jit_rolegroups($arg = null)
 * @method static string|void system_slack_user_column($arg = null)
 * @method static bool|void publicform_available($arg = null)
 * @method static string|void recaptcha_site_key($arg = null)
 * @method static string|void recaptcha_secret_key($arg = null)
 * @method static string|void recaptcha_type($arg = null)
 * @phpstan-consistent-constructor
 */
class System extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

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
     * @return mixed|null
     */
    public static function requestSession($key, $value = null)
    {
        if (is_null($value)) {
            // check array_has
            if (array_has(static::$requestSession, $key)) {
                return static::$requestSession[$key];
            }

            return null;
        } elseif ($value instanceof \Closure) {
            // check array_has
            if (array_has(static::$requestSession, $key)) {
                return static::$requestSession[$key];
            }

            $val = $value();
            static::setRequestSession($key, $val);
            return $val;
        }
        static::setRequestSession($key, $value);
        return null;
    }

    /**
     * Set Request Session.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function setRequestSession($key, $value)
    {
        static::$requestSession[$key] = $value;
    }

    /**
     * Has Request Settion.
     *
     * @param string $key
     * @return bool
     */
    public static function hasRequestSession($key)
    {
        return array_has(static::$requestSession, $key);
    }

    /**
     * clear all request settion
     */
    public static function clearRequestSession($key = null)
    {
        if (!isset($key)) {
            static::$requestSession = [];
        } else {
            array_forget(static::$requestSession, $key);
        }
    }

    /**
     * Get Request Settion key already setted.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getRequestSessionKeys(): \Illuminate\Support\Collection
    {
        $result = collect();
        foreach (static::$requestSession as $key => $value) {
            $result->push($key);
        }
        return $result;
    }

    /**
     * Get and set from cache.
     *
     * @param string $key key name.
     * @param mixed $value setting value.
     * @param bool $onlySetTrue if this arg is true, set cache if val is true.
     * @return mixed
     */
    public static function cache($key, $value = null, $onlySetTrue = false)
    {
        if (is_null($value)) {
            // first, check request session
            if (!is_null($val = static::requestSession($key))) {
                return $val;
            }

            if (boolval(config('exment.use_cache', false)) && Cache::has($key)) {
                $val = Cache::get($key);
                static::setRequestSession($key, $val);
                return $val;
            }

            return null;
        } elseif ($value instanceof \Closure) {
            if (!is_null($val = static::requestSession($key))) {
                return $val;
            }

            if (boolval(config('exment.use_cache', false)) && Cache::has($key)) {
                $val = Cache::get($key);
                static::setRequestSession($key, $val);
                return $val;
            }

            // get value
            $val = $value();

            static::setRequestSession($key, $val);

            // if arg $onlySetTrue is true and $val is not true, not set cache
            if ($onlySetTrue && $val !== true) {
                return $val;
            }

            // set cache
            if (boolval(config('exment.use_cache', false))) {
                Cache::put($key, $val, Define::CACHE_CLEAR_MINUTE);
            }
            return $val;
        }

        static::setRequestSession($key, $value);
        Cache::put($key, $value, Define::CACHE_CLEAR_MINUTE);
    }

    /**
     * reset Cache
     */
    public static function clearCache($key = null)
    {
        static::clearRequestSession($key);

        if (!boolval(config('exment.use_cache', false))) {
            return;
        }

        if (!isset($key)) {
            Cache::flush();
        } else {
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
        $key = static::getConfigKey($name);
        return static::cache($key, function () use ($name, $setting) {
            $system = static::allRecordsCache(function ($record) use ($name) {
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
                $value = is_null($value) ? [] : json_decode_ex($value);
            } elseif ($type == 'array') {
                $value = is_null($value) ? [] : array_filter(explode(',', $value));
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
        $system = System::firstOrNew(['system_name' => $name]);

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
            $system->system_value = is_null($value) ? null : implode(',', array_filter($value));
        } elseif ($type == 'file') {
            $old_value = $system->system_value;
            if (!is_null($value)) {
                $move = array_get($setting, 'move');
                $exmentfile = ExmentFile::storeAs(FileType::SYSTEM, $value, $move, $value->getClientOriginalName());
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
}

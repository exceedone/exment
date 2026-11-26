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
 * @method static string|void ui_design($arg = null)
 * @method static boolean|void api_available($arg = null)
 * @method static boolean|void outside_api($arg = null)
 * @method static boolean|void permission_available($arg = null)
 * @method static boolean|void organization_available($arg = null)
 * @method static boolean|void logging_toggle_available($arg = null)
 * @method static int|void logging_slow_request_seconds($arg = null)
 * @method static int|void logging_slow_sql_seconds($arg = null)
 * @method static int|void logging_max_per_hour($arg = null)
 * @method static string|void filter_search_type($arg = null)
 * @method static string|void system_mail_host($arg = null)
 * @method static string|void system_mail_port($arg = null)
 * @method static string|void system_mail_username($arg = null)
 * @method static string|void system_mail_password($arg = null)
 * @method static string|void system_mail_encryption($arg = null)
 * @method static string|void system_mail_from($arg = null)
 * @method static string|void system_mail_body_type($arg = null)
 * @method static string|void system_mail_from_view_name($arg = null)
 * @method static string|void system_mail_auth($arg = null)
 * @method static string|void system_mail_oauth_tenant($arg = null)
 * @method static string|void system_mail_oauth_client_id($arg = null)
 * @method static string|void system_mail_oauth_client_secret($arg = null)
 * @method static string|void system_mail_oauth_refresh_token($arg = null)
 * @method static string|void system_mail_oauth_connected_at($arg = null)
 * @method static boolean|void userview_available($arg = null)
 * @method static boolean|void userdashboard_available($arg = null)
 * @method static string|void default_date_format($arg = null)
 * @method static int|void grid_pager_count($arg = null)
 * @method static int|void datalist_pager_count($arg = null)
 * @method static array|void grid_filter_disable_flg($arg = null)
 * @method static string|void system_values_pos($arg = null)
 * @method static string|void data_submit_redirect($arg = null)
 * @method static array|void header_user_info($arg = null)
 * @method static array<int, string>|void allowed_locales($arg = null)
 * @method static bool|void locale_from_browser($arg = null)
 * @method static bool|void grid_inline_edit_available($arg = null)
 * @method static boolean|void complex_password($arg = null)
 * @method static int|void password_expiration_days($arg = null)
 * @method static boolean|void first_change_password($arg = null)
 * @method static int|void password_history_cnt($arg = null)
 * @method static bool|void login_notify_new_ip($arg = null)
 * @method static bool|void login_notify_new_ip_mail($arg = null)
 * @method static string|void login_background_color($arg = null)
 * @method static string|void login_page_image($arg = null)
 * @method static string|void login_page_image_type($arg = null)
 * @method static string|void web_ip_filters($arg = null)
 * @method static string|void api_ip_filters($arg = null)
 * @method static mixed web_ip_filter_exempt_users($arg = null)
 * @method static mixed web_ip_filter_exempt_organizations($arg = null)
 * @method static mixed web_ip_filter_exempt_role_groups($arg = null)
 * @method static mixed api_allowed_users($arg = null)
 * @method static mixed api_allowed_organizations($arg = null)
 * @method static mixed api_allowed_role_groups($arg = null)
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
 * @method static boolean|void operation_log_enable_automatic($arg = null)
 * @method static int|void operation_log_keep_days($arg = null)
 * @method static string|void operation_log_automatic_week($arg = null)
 * @method static string|void operation_log_automatic_month($arg = null)
 * @method static string|void operation_log_automatic_day($arg = null)
 * @method static string|void operation_log_automatic_hour($arg = null)
 * @method static string|void operation_log_automatic_minute($arg = null)
 * @method static Carbon|void operation_log_automatic_executed($arg = null)
 * @method static int|null|void log_retention_login_log($arg = null)
 * @method static int|null|void log_retention_permission_change($arg = null)
 * @method static string|null|void audit_report_schedule($arg = null)
 * @method static bool|null|void audit_report_mail($arg = null)
 * @method static int|null|void log_retention_app_log($arg = null)
 * @method static string|null|void log_app_level($arg = null)
 * @method static bool|void log_app_daily($arg = null)
 * @method static string|null|void operation_log_scope($arg = null)
 * @method static bool|void log_archive_enabled($arg = null)
 * @method static string|null|void log_archive_driver($arg = null)
 * @method static int|null|void log_archive_years($arg = null)
 * @method static bool|void log_store_input($arg = null)
 * @method static string|null|void log_ip_mode($arg = null)
 * @method static bool|void log_archive_encrypt($arg = null)
 * @method static bool|void mail_log_no_body($arg = null)
 * @method static bool|void browser_log_pages($arg = null)
 * @method static int|null|void log_retention_browser_log($arg = null)
 * @method static int|null|void log_retention_mcp_log($arg = null)
 * @method static int|null|void log_retention_data_access($arg = null)
 * @method static int|null|void log_retention_data_access_daily($arg = null)
 * @method static string|null|void data_access_summarized_until($arg = null)
 * @method static bool|void log_privacy_notice_shown($arg = null)
 * @method static boolean|void login_use_2factor($arg = null)
 * @method static string|void login_2factor_provider($arg = null)
 * @method static array|void system_admin_users($arg = null)
 * @method static boolean|void show_default_login_provider($arg = null)
 * @method static boolean|void sso_redirect_force($arg = null)
 * @method static boolean|void sso_jit($arg = null)
 * @method static string|void sso_accept_mail_domain($arg = null)
 * @method static array|void jit_rolegroups($arg = null)
 * @method static string|void system_slack_user_column($arg = null)
 * @method static string|void system_line_channel_token($arg = null)
 * @method static string|void system_line_channel_secret($arg = null)
 * @method static string|void system_teams_workflow_url($arg = null)
 * @method static string|void system_lineworks_client_id($arg = null)
 * @method static string|void system_lineworks_client_secret($arg = null)
 * @method static string|void system_lineworks_service_account($arg = null)
 * @method static string|void system_lineworks_private_key($arg = null)
 * @method static string|void system_lineworks_bot_id($arg = null)
 * @method static string|void system_lineworks_user_column($arg = null)
 * @method static string|void system_line_oa_basic_id($arg = null)
 * @method static boolean|void safety_check_auto_enabled($arg = null)
 * @method static int|void safety_check_min_scale($arg = null)
 * @method static int|void safety_check_cooldown_minutes($arg = null)
 * @method static int|void safety_check_comment_window_minutes($arg = null)
 * @method static int|void safety_check_max_bulletin_age_minutes($arg = null)
 * @method static int|void safety_check_resend_throttle_minutes($arg = null)
 * @method static int|void safety_check_answer_url_expire_days($arg = null)
 * @method static array<int, string>|void safety_check_target_organizations($arg = null)
 * @method static int|null|void safety_check_target_view($arg = null)
 * @method static array<int, string>|void safety_check_excluded_users($arg = null)
 * @method static Carbon|void safety_check_last_feed_time($arg = null)
 * @method static string|null|void comment_markdown_start($arg = null)
 * @method static bool|void publicform_available($arg = null)
 * @method static mixed features($arg = null)
 * @method static mixed menu_arrange_backup($arg = null)
 * @method static mixed maintenance($arg = null)
 * @method static mixed maintenance_missed_schedule($arg = null)
 * @method static string|null|void site_id($arg = null)
 * @method static mixed admin_notices_sent($arg = null)
 * @method static mixed scheduled_job_alerted($arg = null)
 * @method static mixed tuning_data_stats($arg = null)
 * @method static string|void recaptcha_site_key($arg = null)
 * @method static string|void recaptcha_secret_key($arg = null)
 * @method static string|void recaptcha_type($arg = null)
 * @method static string|void meili_host($arg = null)
 * @method static string|void meili_key($arg = null)
 * @method static string|void meili_index($arg = null)
 * @method static boolean|void meili_global_search($arg = null)
 * @method static boolean|void meili_realtime_sync($arg = null)
 * @method static int|void meili_batch_size($arg = null)
 * @method static boolean|void meili_repair_enabled($arg = null)
 * @method static string|void meili_repair_at($arg = null)
 * @method static string|void meili_filter_mode($arg = null)
 * @method static array<string, mixed>|void meili_indexed_at($arg = null)
 * @phpstan-consistent-constructor
 */
class System extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

    protected $casts = ['role' => 'json'];
    protected $primaryKey = 'system_name';
    public $incrementing = false;

    // @phpstan-ignore-next-line
    protected static $requestSession = [];


    // @phpstan-ignore-next-line
    public static function __callStatic($name, $argments)
    {
        // Get system setting value
        if (static::hasFunction($name)) {
            $setting = \Exceedone\Exment\Services\CoreExtensions::systemSettings()[$name];
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

    // @phpstan-ignore-next-line
    public static function clearRequestSession($key = null)
    {
        if (!isset($key)) {
            static::$requestSession = [];
        } else {
            array_forget(static::$requestSession, $key);
        }
    }

    /**
     * All request session entries, to put back after a block whose own cache
     * entries must not outlive it.
     *
     * @return array<string,mixed>
     */
    public static function getRequestSessionAll(): array
    {
        return static::$requestSession;
    }

    /**
     * @param array<string,mixed> $entries
     */
    public static function setRequestSessionAll(array $entries): void
    {
        static::$requestSession = $entries;
    }

    /**
     * Get Request Settion key already setted.
     *
     * @return \Illuminate\Support\Collection
     */

    // @phpstan-ignore-next-line
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

            // one read of the store: has() reads the value too, and a null is not kept (ISS00413)
            if (boolval(config('exment.use_cache', false)) && !is_null($val = static::store()->get(static::storeKey($key)))) {
                static::setRequestSession($key, $val);
                return $val;
            }

            return null;
        } elseif ($value instanceof \Closure) {
            if (!is_null($val = static::requestSession($key))) {
                return $val;
            }

            // one read of the store: has() reads the value too, and a null is not kept (ISS00413)
            if (boolval(config('exment.use_cache', false)) && !is_null($val = static::store()->get(static::storeKey($key)))) {
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
                static::store()->put(static::storeKey($key), $val, Define::CACHE_CLEAR_MINUTE);
            }
            return $val;
        }

        static::setRequestSession($key, $value);
        static::store()->put(static::storeKey($key), $value, Define::CACHE_CLEAR_MINUTE);
    }

    /** @var string|null the generation of the cache last read from the database */
    protected static $cacheGeneration;

    /** @var float when it was read (microtime) */
    protected static $cacheGenerationReadAt = 0.0;

    /**
     * The generation of the cache: a value kept in the database (table systems) that changes
     * each time the cache is cleared. What is kept in the cache store is kept under it, so a
     * server whose store was not cleared (two web servers with a file cache each, a worker of
     * the queue) no longer reads what it kept before: it sees the new generation and works the
     * values out again. Read once for a request (again after a second, for a process that
     * runs long).
     */
    public static function cacheGeneration(): string
    {
        $now = microtime(true);
        if (isset(static::$cacheGeneration) && $now - static::$cacheGenerationReadAt < 1.0) {
            return static::$cacheGeneration;
        }
        try {
            $value = \DB::table((new static())->getTable())
                ->where('system_name', Define::SYSTEM_KEY_CACHE_GENERATION)->value('system_value');
        } catch (\Throwable $e) {
            // no database yet (the install): nothing is shared with another server
            $value = null;
        }
        static::$cacheGenerationReadAt = $now;
        return static::$cacheGeneration = strval($value ?? '0');
    }

    /**
     * A new generation of the cache, for every server of the site (see cacheGeneration).
     * Inside a transaction that is rolled back, the generation of before stays.
     */
    public static function renewCacheGeneration(): string
    {
        $generation = dechex(time()) . bin2hex(random_bytes(4));
        try {
            \DB::table((new static())->getTable())->updateOrInsert(
                ['system_name' => Define::SYSTEM_KEY_CACHE_GENERATION],
                ['system_value' => $generation]
            );
        } catch (\Throwable $e) {
            // no database yet (the install)
            return static::cacheGeneration();
        }
        static::$cacheGenerationReadAt = microtime(true);
        return static::$cacheGeneration = $generation;
    }

    /**
     * The key of a value in the cache store: under the generation of the cache. Without the
     * cache of Exment (EXMENT_USE_CACHE) nothing is read from the store, and the generation
     * is not asked.
     *
     * @param string $key
     */
    protected static function storeKey($key): string
    {
        if (!boolval(config('exment.use_cache', false))) {
            return strval($key);
        }
        return static::STORE_KEY_PREFIX . static::cacheGeneration() . ':' . $key;
    }

    /** what every key of the cache of Exment starts with in the cache store */
    public const STORE_KEY_PREFIX = 'exm:';

    /** the name of the store made for the cache of Exment when the cache of the site is in files */
    public const FILE_STORE = 'exment_kept';

    /**
     * The driver of the cache store of the site (file, redis, database, array...).
     */
    protected static function storeDriver(): string
    {
        $default = strval(config('cache.default'));
        return strval(config("cache.stores.{$default}.driver", $default));
    }

    /**
     * Where the cache of Exment is kept: the cache store of the site. When that is files,
     * a folder of its own inside it ("exment"), so that it can be emptied alone (the files of
     * a store are never removed when their time is over, only when they are read).
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    protected static function store()
    {
        if (static::storeDriver() !== 'file') {
            return Cache::store();
        }
        if (is_null(config('cache.stores.' . static::FILE_STORE))) {
            $default = strval(config('cache.default'));
            $settings = (array) config("cache.stores.{$default}");
            $path = strval($settings['path'] ?? storage_path('framework/cache/data'));
            config(['cache.stores.' . static::FILE_STORE => array_merge($settings, [
                'driver' => 'file',
                'path' => $path . DIRECTORY_SEPARATOR . 'exment',
            ])]);
        }
        return Cache::store(static::FILE_STORE);
    }

    /**
     * Remove what the cache of Exment kept on this server, and only that: the rest of the
     * cache store (the count of wrong passwords, the signal to restart the queue, the locks)
     * is not Exment's to clear. A store that drops what is past its time itself (Redis,
     * Memcached) needs nothing: the values of the generation before are no longer read, and
     * go when their time is over (an hour).
     */
    protected static function dropKept(): void
    {
        try {
            $driver = static::storeDriver();
            if ($driver === 'file') {
                static::store()->getStore()->flush();
            } elseif ($driver === 'database') {
                $default = strval(config('cache.default'));
                $prefix = strval(Cache::store()->getStore()->getPrefix()) . static::STORE_KEY_PREFIX;
                \DB::connection(config("cache.stores.{$default}.connection"))
                    ->table(strval(config("cache.stores.{$default}.table", 'cache')))
                    ->where('key', 'like', addcslashes($prefix, '%_\\') . '%')
                    ->delete();
            }
        } catch (\Throwable $e) {
            // the values of the generation before are not read in any case
            \Log::debug('[Exment] the kept cache was not removed: ' . $e->getMessage());
        }
    }

    /**
     * reset Cache
     */

    // @phpstan-ignore-next-line
    public static function clearCache($key = null)
    {
        static::clearRequestSession($key);

        if (!boolval(config('exment.use_cache', false))) {
            return;
        }

        if (!isset($key)) {
            // a new generation: no server of the site reads what it kept before. Then what
            // this server kept is removed (only what is Exment's, see dropKept).
            static::renewCacheGeneration();
            static::dropKept();
        } else {
            static::store()->forget(static::storeKey($key));
        }
    }

    /**
     * Clear the whole cache once the writes of the current transaction are committed.
     * Outside a transaction it clears immediately. A rolled back transaction drops the callback.
     *
     * Use it when the cached data depends on rows written in the same transaction (e.g. the
     * organization membership pivot): clearing before the commit lets a concurrent request
     * rebuild the cache from the old rows.
     *
     * @param string|null $connection connection that performs the writes. null: default connection
     * @return void
     */
    public static function clearCacheAfterCommit(?string $connection = null): void
    {
        try {
            \DB::connection($connection)->afterCommit(function () {
                static::clearCache();
            });
        } catch (\Throwable $ex) {
            // a connection without a transactions manager (none is set outside the framework)
            static::clearCache();
        }
    }

    /**
     * whether System_function keyname
     */

    // @phpstan-ignore-next-line
    public static function hasFunction($name)
    {
        return array_key_exists($name, \Exceedone\Exment\Services\CoreExtensions::systemSettings());
    }


    // @phpstan-ignore-next-line
    public static function get_system_keys($group = null)
    {
        $keys = [];
        foreach (\Exceedone\Exment\Services\CoreExtensions::systemSettings() as $k => $v) {
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

    // @phpstan-ignore-next-line
    public static function get_system_values($group = null)
    {
        $array = [];
        foreach (static::get_system_keys($group) as $k) {
            $array[$k] = static::{$k}();
        }

        return $array;
    }


    // @phpstan-ignore-next-line
    protected static function getset_system_value($name, $setting, $argments)
    {
        if (count($argments) > 0) {
            static::set_system_value($name, $setting, $argments[0]);
            return null;
        } else {
            return static::get_system_value($name, $setting);
        }
    }


    // @phpstan-ignore-next-line
    protected static function get_system_value($name, $setting)
    {
        $key = static::getConfigKey($name);
        return static::cache($key, function () use ($name, $setting) {
            // by the index of system_name: a scan of every row was run for each setting (ISS00413)
            $system = static::recordsCacheBy(['system_name'], [$name])->first();

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
                if (boolval(array_get($setting, 'data_key'))) {
                    // the keys of the encrypted columns when the site has them (ISS02929)
                    $value = is_null($value) ? null : \Exceedone\Exment\Services\Encryption\Secrets::decrypt(strval($value));
                } else {
                    try {
                        $value = is_null($value) ? null : decrypt($value);
                    } catch (\Exception $ex) {
                    }
                }
            }
            return $value;
        });
    }


    // @phpstan-ignore-next-line
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
            // "data_key": with the keys of the encrypted columns when the site has them (ISS02929),
            // else with APP_KEY as before (the bare payload, as the other secrets)
            $system->system_value = is_null($value) ? null : (boolval(array_get($setting, 'data_key'))
                ? \Exceedone\Exment\Services\Encryption\Secrets::encrypt(strval($value), false)
                : encrypt($value));
        } else {
            $system->system_value = $value;
        }
        $system->saveOrFail();

        return $system;
    }

    /**
     * destory value
     */

    // @phpstan-ignore-next-line
    public static function deleteValue($name)
    {
        $system = System::find($name);
        if (!isset($system)) {
            return;
        }

        // @phpstan-ignore-next-line
        $old_value = $system->system_value;

        // change set value by type
        $setting = \Exceedone\Exment\Services\CoreExtensions::systemSettings()[$name];
        $type = array_get($setting, 'type');

        if ($type == 'file') {
            // remove old file
            if (!is_null($old_value)) {
                ExmentFile::deleteFileInfo($old_value);
            }
        }

        // @phpstan-ignore-next-line
        $system->system_value = null;

        // @phpstan-ignore-next-line
        $system->save();

        return $system;
    }


    // @phpstan-ignore-next-line
    protected static function getConfigKey($name)
    {
        return sprintf(Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, $name);
    }
}

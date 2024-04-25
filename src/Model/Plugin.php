<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\PluginEventType;
use Exceedone\Exment\Enums\PluginButtonType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Storage\Disk\PluginDiskService;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Collection;

/**
 * @property mixed $plugin_type
 * @property mixed $plugin_name
 * @property mixed $plugin_view_name
 * @property mixed $author
 * @property mixed $description
 * @property mixed $uuid
 * @property mixed $version
 * @property mixed $active_flg
 * @property mixed $options
 * @phpstan-consistent-constructor
 */
class Plugin extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $casts = ['options' => 'json', 'custom_options' => 'json'];

    public function setPluginTypesAttribute($pluginTypes)
    {
        if (is_null($pluginTypes)) {
            $this->attributes['plugin_types'] = null;
        } elseif (is_numeric($pluginTypes)) {
            $this->attributes['plugin_types'] = PluginType::getEnum($pluginTypes)->getValue() ?? null;
        } else {
            $array = collect(explode(',', $pluginTypes))->filter(function ($value, $key) {
                return $value !== null && strlen($value) > 0;
            })->map(function ($pluginType) {
                return PluginType::getEnum($pluginType)->getValue() ?? null;
            })->toArray();

            $this->attributes['plugin_types'] = implode(',', $array);
        }
    }

    public function getPluginTypesAttribute()
    {
        $plugin_types = array_get($this->attributes, 'plugin_types');
        if (is_array($plugin_types)) {
            return $plugin_types;
        }
        return explode(",", $plugin_types);
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        $user = \Exment::user();
        return isset($user) ? !$user->hasPermission(Permission::PLUGIN_ALL) : true;
    }

    /**
     * Whether has permission, permission level
     * $role_key * if set array, check whether either items.
     * @param array|string $role_key
     */
    public function hasPermission($role_key)
    {
        return \Exment::user()->hasPermissionPlugin($this->id, $role_key);
    }

    /**
     * Get All plugin ids, user has setting permission
     *
     * @return array|\Illuminate\Support\Collection
     */
    public static function getIdsHasSettingPermission()
    {
        return System::requestSession(Define::SYSTEM_KEY_SESSION_PLUGIN_ALL_SETTING_IDS, function () {
            if (\Exment::user()->hasPermission(Permission::PLUGIN_ALL)) {
                return static::allRecords()->pluck('id');
            }

            $permissions = \Exment::user()->allPermissions()->filter(function ($permission) {
                return RoleType::PLUGIN == $permission->getRoleType() && array_key_exists(Permission::PLUGIN_SETTING, $permission->getPermissionDetails());
            });

            return $permissions->map(function ($permission) {
                return $permission->getPluginId();
            })->toArray();
        });
    }

    /**
     * Match(contain) plugin type using enum PluginType
     *
     * @return bool is match plugin type. if $plugin_types is multiple, whether contains.
     */
    public function matchPluginType($plugin_types)
    {
        $plugin_types = toArray($plugin_types);
        foreach ($this->plugin_types as $this_plugin_type) {
            if (in_array($this_plugin_type, $plugin_types)) {
                return true;
            }
        }

        return false;
    }

    public function isPluginTypeUri()
    {
        return $this->matchPluginType(PluginType::PLUGIN_TYPE_PUBLIC_CLASS());
    }

    public static function getPluginByUUID($uuid)
    {
        if ($uuid instanceof Plugin) {
            return $uuid;
        }
        return static::getPluginsCache()->first(function ($plugin) use ($uuid) {
            return strcmp_ex($plugin->uuid, $uuid) == 0;
        });
    }

    public static function getPluginByName($plugin_name)
    {
        return static::getPluginsCache()->first(function ($plugin) use ($plugin_name) {
            return strcmp_ex($plugin->plugin_name, $plugin_name) == 0;
        });
    }

    /**
     * Get plugin by custom_table name
     * Where active_flg = 1 and target_tables contains custom_table id
     * Filtering only accessible.
     *
     * @param CustomTable|null $custom_table
     * @param bool $filterAccessible
     * @return mixed
     */
    public static function getPluginsByTable($custom_table, $filterAccessible = true)
    {
        if (is_null($custom_table)) {
            return [];
        }

        $func = $filterAccessible ? 'getAccessableByPluginTypes' : 'getByPluginTypes';
        return static::{$func}(PluginType::PLUGIN_TYPE_CUSTOM_TABLE())->filter(function ($plugin) use ($custom_table) {
            $target_tables = array_get($plugin, 'options.target_tables', []);
            if (is_nullorempty($target_tables)) {
                return false;
            }

            if (!in_array(CustomTable::getEloquent($custom_table)->table_name, $target_tables)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get Batches filtering hour
     *
     * @return Collection
     */
    public static function getBatches()
    {
        $now = Carbon::now();
        $hh = $now->hour;
        return static::getByPluginTypes(PluginType::BATCH)->filter(function ($plugin) use ($hh) {
            return is_null(array_get($plugin, 'options.batch_cron')) && array_get($plugin, 'options.batch_hour') == $hh;
        });
    }

    /**
     * Get Batches filtering has Cron
     *
     * @return Collection
     */
    public static function getCronBatches()
    {
        return static::getByPluginTypes(PluginType::BATCH)->filter(function ($plugin) {
            return !is_null(array_get($plugin, 'options.batch_cron'));
        });
    }

    /**
     * Get Plugin's class object
     *
     * @return mixed \Exceedone\Exment\Services\Plugin\PluginBase
     */
    public function getClass($plugin_type, $options = [])
    {
        $options = array_merge(
            [
            'throw_ex' => true,
            ],
            $options
        );

        if (is_null($plugin_type)) {
            $class = PluginType::getPluginClass(null, $this, $options);
        } elseif ($this->matchPluginType($plugin_type)) {
            $plugin_type = PluginType::getEnum($plugin_type);
            $class = PluginType::getPluginClass($plugin_type, $this, $options);
        }

        if (!isset($class) && $options['throw_ex']) {
            throw new \Exception('plugin not found');
        }

        return $class ?? null;
    }

    /**
     * Get namespace path
     */
    public function getNameSpace(...$pass_array)
    {
        $array = ["App", "Plugins", pascalize($this->plugin_name)];
        if (count($pass_array) > 0) {
            $array = array_merge(
                $array,
                $pass_array
            );
        }
        return namespace_join(...$array);
    }

    /**
     * Get plugin path. (not fullpath. relation from laravel root)
     * if $pass_array is empty, return plugin folder path.
     */
    public function getPath(...$pass_array)
    {
        $pluginPath = pascalize(preg_replace('/\s+/', '', $this->plugin_name));

        if (count($pass_array) > 0) {
            $pluginPath = array_merge(
                [$pluginPath],
                $pass_array
            );
        } else {
            $pluginPath = [$pluginPath];
        }
        //return path_join('plugins', ...$pluginPath);
        return path_join(...$pluginPath);
    }

    /**
     * Get plugin fullpath.
     * if $pass_array is empty, return plugin folder full path.
     */
    public function getFullPath(...$pass_array)
    {
        $diskService = new PluginDiskService($this);
        // sync from crowd.
        $diskService->syncFromDisk();
        $this->requirePlugin($diskService);

        $plugin_fullpath = $diskService->localSyncDiskItem()->dirFullPath();
        return path_join($plugin_fullpath, ...$pass_array);
    }

    /**
     * Get plugin file paths. relative path, not fullpath
     */
    public function getPluginFilePaths($dirPath = null, $subdir = true, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $dirPath) = $this->initPluginDisk($dirPath, $diskService);

        $func = $subdir ? 'allFiles' : 'files';
        $files = $disk->{$func}($dirPath);

        // remove "(pluginname)/"
        $files = collect($files)->map(function ($file) use ($dirName) {
            return ltrim(ltrim($file, $dirName), '/');
        })->toArray();

        return $files;
    }

    /**
     * Get plugin directories paths. relative path, not fullpath
     */
    public function getPluginDirPaths($dirPath = null, $subdir = true, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $dirPath) = $this->initPluginDisk($dirPath, $diskService);

        $func = $subdir ? 'allDirectories' : 'directories';
        $dirs = $disk->{$func}($dirPath);

        // remove "(pluginname)/"
        $dirs = collect($dirs)->map(function ($dir) use ($dirName) {
            return ltrim(ltrim($dir, $dirName), '/');
        })->toArray();

        return $dirs;
    }

    /**
     * Get plugin file data
     *
     * @param string $path file relative path
     * @param PluginDiskService|null $diskService
     * @return mixed
     * @throws FileNotFoundException
     */
    public function getPluginFiledata(string $path, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $filePath) = $this->initPluginDisk($path, $diskService);

        return $disk->get($filePath);
    }

    /**
     * Put plugin file. upload to crowd.
     *
     * @param string $path file relative path
     * @param $file
     * @param PluginDiskService|null $diskService
     * @return mixed
     * @throws FileNotFoundException
     */
    public function putPluginFile(string $path, $file, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $filePath) = $this->initPluginDisk($path, $diskService, ['exceptionFileNotFound' => false]);

        return $disk->put($filePath, $file);
    }

    /**
     * Putasfile plugin file. upload to crowd.
     *
     * @param string|null $dirPath file relative path
     * @param string $fileName
     * @param $file
     * @param PluginDiskService|null $diskService
     * @return mixed
     * @throws FileNotFoundException
     */
    public function putAsPluginFile(?string $dirPath, string $fileName, $file, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $dirPath) = $this->initPluginDisk($dirPath, $diskService, ['exceptionFileNotFound' => false]);

        return $disk->putFileAs($dirPath, $file, $fileName);
    }

    /**
     * Delete plugin file. upload to crowd.
     *
     * @param string $path file relative path
     * @param PluginDiskService|null $diskService
     * @return mixed
     * @throws FileNotFoundException
     */
    public function deletePluginFile(string $path, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $filePath) = $this->initPluginDisk($path, $diskService, ['exceptionFileNotFound' => false]);

        // delete local disk
        $diskService->localSyncDiskItem()->disk()->delete($filePath);
        return $disk->delete($filePath);
    }

    /**
     * is this path is directory or not
     *
     * @param string|null $path
     * @param PluginDiskService|null $diskService
     * @return boolean
     */
    public function isPathDir(?string $path, ?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $filePath) = $this->initPluginDisk($path, $diskService);

        return $disk->getDriver()->directoryExists($filePath);
    }

    /**
     * call require
     *
     * @param PluginDiskService $diskService
     * @return void
     */
    public function requirePlugin(?PluginDiskService $diskService = null)
    {
        list($diskService, $disk, $dirName, $filePath) = $this->initPluginDisk(null, $diskService, ['sync' => true]);

        // call plugin
        $fullPathDir = \Exment::replaceBackToSlash($diskService->localSyncDiskItem()->dirFullPath());

        \Exment::classLoader()->registerDir($fullPathDir, $this->getNameSpace());
    }

    /**
     * Initialize plugin disk.
     *
     * @param string $path
     * @param PluginDiskService|null $diskService
     * @param array $options
     * @return array offset 0:PluginDiskService, 1:disk, 2: root dir name, 3: joined path.
     */
    protected function initPluginDisk(string $path = null, ?PluginDiskService $diskService = null, array $options = [])
    {
        $options = array_merge([
            'sync' => false,
            'exceptionFileNotFound' => true,
        ], $options);

        if (!isset($diskService)) {
            $diskService = new PluginDiskService($this);
            if (boolval($options['sync'])) {
                $diskService->syncFromDisk();
            }
        }

        $disk = $diskService->diskItem()->disk();
        $dirName = $diskService->diskItem()->dirName();

        if (!$disk->exists($dirName)) {
            throw new FileNotFoundException($dirName);
        }

        $filePath = path_join($dirName, $path);
        if (!$disk->exists($filePath) && boolval($options['exceptionFileNotFound'])) {
            throw new FileNotFoundException($filePath);
        }

        return [$diskService, $disk, $dirName, $filePath];
    }




    //Check all plugins satisfied take out from function getPluginByTableId
    //If calling event is not button, then call execute function of this plugin
    //Because namspace can't contains specifies symbol
    /**
     * @param string|null $event
     */
    public static function pluginExecuteEvent($event = null, $custom_table = null, $options = [])
    {
        $plugins = static::getPluginsByTable($custom_table, false);

        if ($event !== null && !isset($options['event_type'])) {
            $options['event_type'] = $event;
        }

        if (isset($custom_table) && !isset($options['custom_table'])) {
            $options['custom_table'] = $custom_table;
        }

        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // if $plugin_types is not trigger, continue
                if (!$plugin->matchPluginType(PluginType::PLUGIN_TYPE_EVENT())) {
                    continue;
                }

                $event_triggers = array_get($plugin, 'options.event_triggers', []);
                $enum = PluginEventType::getEnum($event);

                $options['throw_ex'] = false;

                if (in_array($event, (array)$event_triggers) && isset($enum)) {
                    // call PluginType::EVENT as throw_ex is false
                    $class = $plugin->getClass(PluginType::EVENT, $options);

                    $class = isset($class) ? $class : $plugin->getClass(PluginType::TRIGGER, $options);

                    // if isset $class, call
                    if (isset($class)) {
                        $pluginCalled = $class->execute();
                    }
                    // if cannot call class, set error
                    else {
                        admin_error(exmtrans('common.error'), $plugin->getCannotReadMessage());
                    }
                }
            }
        }
    }

    //Check all plugins satisfied take out from function getPluginByTableId
    //If calling event is button, then add event into array, then return array to make button with action
    /**
     * @param null $event
     * @return array
     */
    public static function pluginPreparingButton($event = null, $custom_table = null)
    {
        $plugins = static::getPluginsByTable($custom_table, true);

        if (empty($plugins)) {
            return [];
        }

        $options = ['throw_ex' => false];
        $buttonList = [];
        foreach ($plugins as $plugin) {
            if (!$plugin->matchPluginType(PluginType::PLUGIN_TYPE_BUTTON())) {
                continue;
            }

            $plugin_types = toArray(array_get($plugin, 'plugin_types'));

            foreach ($plugin_types as $plugin_type) {
                switch ($plugin_type) {
                    case PluginType::DOCUMENT:
                        if (!in_array($event, [PluginButtonType::FORM_MENUBUTTON_SHOW])) {
                            break;
                        }

                        // call PluginType::BUTTON as throw_ex is false
                        $class = $plugin->getClass(PluginType::DOCUMENT, $options);
                        if (!isset($class)) {
                            admin_error(exmtrans('common.error'), $plugin->getCannotReadMessage());
                            break;
                        }

                        $buttonList[] = [
                            'plugin_type' => $plugin_type,
                            'plugin' => $plugin,
                        ];
                        break;
                    case PluginType::TRIGGER:
                    case PluginType::BUTTON:
                        $event_triggers = toArray(array_get($plugin->options, 'event_triggers', []));
                        if (!in_array($event, $event_triggers) || is_null(PluginButtonType::getEnum($event))) {
                            break;
                        }

                        // call PluginType::BUTTON as throw_ex is false
                        $class = $plugin->getClass(PluginType::BUTTON, $options);
                        $class = isset($class) ? $class : $plugin->getClass(PluginType::TRIGGER, $options);
                        if (!isset($class)) {
                            admin_error(exmtrans('common.error'), $plugin->getCannotReadMessage());
                            break;
                        }

                        $buttonList[] = [
                            'plugin_type' => $plugin_type,
                            'plugin' => $plugin,
                        ];
                        break;
                }
            }
        }

        return $buttonList;
    }

    /**
     * get plugins for import
     *
     * @param CustomTable $custom_table
     * @return array
     */
    public static function pluginPreparingImport($custom_table)
    {
        $plugins = static::getPluginsByTable($custom_table, true);

        $itemlist = [];
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // get plugin_types
                $plugin_types = array_get($plugin, 'plugin_types');
                foreach ($plugin_types as $plugin_type) {
                    switch ($plugin_type) {
                        case PluginType::IMPORT:
                            $itemlist[$plugin->id] = $plugin->plugin_view_name;
                            break;
                    }
                }
            }
        }
        return $itemlist;
    }

    /**
     * execute custom plugin validate
     */
    public static function pluginValidator($custom_table, $options = [])
    {
        $plugins = static::getPluginsByTable($custom_table, false);
        $messages = [];

        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // if $plugin_types is not validator, continue
                if (!$plugin->matchPluginType(PluginType::VALIDATOR)) {
                    continue;
                }

                $class = $plugin->getClass(PluginType::VALIDATOR, $options);
                if (!$class->validate()) {
                    $messages = array_merge_recursive($messages, $class->messages());
                }
            }
        }
        return $messages;
    }

    /**
     * execute validate destroy
     */
    public static function pluginValidateDestroy($model, $options = [])
    {
        $plugins = static::getPluginsByTable($model->custom_table, false);
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // if $plugin_types is not validator, continue
                if (!$plugin->matchPluginType(PluginType::VALIDATOR)) {
                    continue;
                }
                $class = $plugin->getClass(PluginType::VALIDATOR, $options);
                // if isset $class, call
                if (isset($class)) {
                    if (method_exists($class, 'validateDestroy')) {
                        $res = $class->validateDestroy($model);
                        if ($res === false) {
                            return [
                                'status'  => false,
                                'message' =>  exmtrans('error.delete_failed'),
                            ];
                        }
                        if (is_array($res) && array_get($res, 'status') === false) {
                            return $res;
                        }
                    }
                }
                // if cannot call class, set error
                else {
                    return [
                        'status'  => false,
                        'message' =>  exmtrans('error.delete_failed'),
                    ];
                }
            }
        }
    }

    /**
     * Get plugins filtering accessable by selecting plugin_type
     */
    public static function getAccessableByPluginTypes($plugin_types, $getAsClass = false)
    {
        return static::getByPluginTypes($plugin_types, $getAsClass)
            ->filter(function ($plugin) {
                $login_user = \Exment::user();
                if (!$login_user || !$login_user->hasPermissionPlugin($plugin, Permission::PLUGIN_ACCESS)) {
                    return false;
                }
                return true;
            });
    }

    /**
     * Get plugins by selecting plugin_type
     */
    public static function getByPluginTypes($plugin_types, $getAsClass = false)
    {
        return static::getPluginPublicSessions($plugin_types, $getAsClass);
    }

    /**
     * Get plugin page object model. Needs Page's endpoint.
     *
     * @return Collection
     */
    public static function getPluginPages()
    {
        return static::getPluginPublicSessions(PluginType::PLUGIN_TYPE_PLUGIN_PAGE(), true);
    }

    /**
     * Get plugin scripts and styles. Needs script and css endpoint, and read public file.
     *
     * @return Collection
     */
    public static function getPluginScriptStyles()
    {
        return static::getPluginPublicSessions(PluginType::PLUGIN_TYPE_SCRIPT_STYLE(), true);
    }

    /**
     * Get plugin sessions
     *
     * @return Collection
     */
    protected static function getPluginPublicSessions($targetPluginTypes, $getAsClass = false)
    {
        $plugins = static::getPluginsCache();
        $plugins = $plugins->filter(function ($plugin) use ($targetPluginTypes) {
            if (!$plugin->matchPluginType($targetPluginTypes)) {
                return false;
            }
            return true;
        });

        if (!$getAsClass) {
            return $plugins;
        }

        return $plugins->map(function ($plugin) use ($targetPluginTypes) {
            if (!is_array($targetPluginTypes)) {
                $targetPluginTypes = [$targetPluginTypes];
            }

            foreach ($targetPluginTypes as $targetPluginType) {
                $class = $plugin->getClass($targetPluginType, ['throw_ex' => false]);
                if (isset($class)) {
                    return $class;
                }
            }
        })->filter();
    }

    protected static function getPluginsCache()
    {
        // get plugin page's
        return System::cache(Define::SYSTEM_KEY_SESSION_PLUGINS, function () {
            // get plugin
            $plugins = Plugin::allRecordsCache(function ($plugin) {
                if (!boolval(array_get($plugin, 'active_flg'))) {
                    return false;
                }

                return true;
            }, false);

            return collect($plugins);
        });
    }

    /**
     * Get plugin page model using request uri
     *
     * return : \Exceedone\Exment\Services\Plugin\PluginBase
     * @return mixed
     */
    public static function getPluginPageModel()
    {
        // get namespace
        $patterns = ['@plugins/([^/\?]+)@', '@dashboardbox/plugin/([^/\?]+)@'];
        foreach ($patterns as $pattern) {
            preg_match($pattern, request()->url(), $matches);

            // @phpstan-ignore-next-line
            if (!isset($matches) || count($matches) <= 1) {
                continue;
            }

            $pluginName = $matches[1];

            // get target plugin
            $plugin = static::getPluginsCache()->first(function ($plugin) use ($pluginName) {
                if (!$plugin->matchPluginType(Plugintype::PLUGIN_TYPE_PUBLIC_CLASS())) {
                    return false;
                }

                return pascalize(array_get($plugin, 'plugin_name')) == pascalize($pluginName)
                    || $plugin->getOption('uri') == $pluginName
                ;
            });

            if (!isset($plugin)) {
                continue;
            }

            // get class
            foreach (Plugintype::PLUGIN_TYPE_PUBLIC_CLASS() as $plugin_type) {
                if ($plugin->matchPluginType($plugin_type)) {
                    return $plugin->getClass($plugin_type);
                }
            }
        }
    }

    /**
     * Get Root url
     *
     * @return string
     */
    public function getRootUrl($plugin_type): string
    {
        if ($plugin_type == PluginType::PAGE || $plugin_type == PluginType::CRUD) {
            return admin_urls($this->getRouteUri());
        } elseif ($plugin_type == PluginType::API) {
            return admin_urls('api', $this->getRouteUri());
        }
        return '';
    }

    /**
     * Get full url for page
     *
     * @return string
     */
    public function getFullUrl(...$pass_array)
    {
        return admin_urls($this->getRouteUri(...$pass_array));
    }

    /**
     * Get route uri for page
     *
     * @return string
     */
    public function getRouteUri(...$pass_array)
    {
        return url_join('plugins', $this->getOptionUri(), ...$pass_array);
    }

    /**
     * Get option uri.
     * set snake_case.
     *
     * @return string
     */
    public function getOptionUri()
    {
        $uri = $this->getOption('uri');
        if (!isset($uri)) {
            $uri = array_get($this, 'plugin_name');
        }
        return snake_case($uri);
    }

    public function getCannotReadMessage()
    {
        return exmtrans('plugin.error.cannot_read', [
            'plugin_view_name' => $this->plugin_view_name
        ]);
    }

    /**
     * get eloquent using request settion.
     */
    public static function getEloquent($obj, $withs = [])
    {
        if ($obj instanceof Plugin) {
            return $obj;
        }

        if ($obj instanceof \stdClass) {
            $obj = (array)$obj;
        }
        // get id or array value
        if (is_array($obj)) {
            // get id or table_name
            if (array_key_value_exists('id', $obj)) {
                $obj = array_get($obj, 'id');
            } elseif (array_key_value_exists('plugin_name', $obj)) {
                $obj = array_get($obj, 'plugin_name');
            } else {
                return null;
            }
        }

        // get eloquent model
        if (is_numeric($obj)) {
            $query_key = 'id';
        } elseif (is_string($obj)) {
            $query_key = 'plugin_name';
        }
        if (isset($query_key)) {
            // get table
            $obj = static::allRecords(function ($plugin) use ($query_key, $obj) {
                return array_get($plugin, $query_key) == $obj;
            })->first();
            if (!isset($obj)) {
                return null;
            }
        }

        return $obj;
    }

    public function getCustomOption($key, $default = null)
    {
        return $this->getJson('custom_options', $key, $default);
    }
    public function setCustomOption($key, $val = null)
    {
        return $this->setJson('custom_options', $key, $val);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->prepareJson('options');
            $model->prepareJson('custom_options');
        });
    }
}

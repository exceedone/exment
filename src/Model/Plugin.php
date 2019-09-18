<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\DocumentType;
use Exceedone\Exment\Enums\PluginType;
use Carbon\Carbon;

class Plugin extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;

    protected $casts = ['options' => 'json', 'custom_options' => 'json'];
    
    public function setPluginTypesAttribute($pluginTypes)
    {
        if (is_null($pluginTypes)) {
            $this->attributes['plugin_types'] = null;
        } elseif (is_numeric($pluginTypes)) {
            $this->attributes['plugin_types'] = PluginType::getEnum($pluginTypes)->getValue() ?? null;
        } else {
            $array = collect(explode(',', $pluginTypes))->filter(function ($value, $key) {
                return isset($value) && strlen($value) > 0;
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
     * Patch plugin type using enum PluginType
     *
     * @return void
     */
    public function matchPluginType($plugin_types)
    {
        if (!is_array($plugin_types)) {
            $plugin_types = [$plugin_types];
        }

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
        return static::where('uuid', '=', $uuid)
            ->first();
    }

    public static function getPluginByName($plugin_name)
    {
        return static::where('plugin_name', '=', $plugin_name)
            ->first();
    }

    //Get plugin by custom_table name
    //Where active_flg = 1 and target_tables contains custom_table id
    /**
     * @param $id
     * @return mixed
     */
    public static function getPluginsByTable($custom_table)
    {
        if (!isset($custom_table)) {
            return [];
        }

        return static::getByPluginTypes([PluginType::TRIGGER, PluginType::DOCUMENT, PluginType::IMPORT])->filter(function ($plugin) use ($custom_table) {
            $target_tables = array_get($plugin, 'options.target_tables');
            if (!in_array(CustomTable::getEloquent($custom_table)->table_name, $target_tables)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get Batches filtering hour
     *
     * @return void
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
     * @return void
     */
    public static function getCronBatches()
    {
        return static::getByPluginTypes(PluginType::BATCH)->filter(function ($plugin) {
            return !is_null(array_get($plugin, 'options.batch_cron'));
        });
    }

    /**
     * Get document type
     */
    public function getDocumentType()
    {
        return array_get($this->options, 'document_type', DocumentType::EXCEL);
    }

    /**
     * Get Plugin's class object
     *
     * @return void
     */
    public function getClass($plugin_type, $options = [])
    {
        extract(
            array_merge(
                [
                'throw_ex' => true,
                ],
                $options
            )
        );

        if (is_null($plugin_type)) {
            $class = PluginType::getPluginClass(null, $this, $options);
        } elseif ($this->matchPluginType($plugin_type)) {
            $plugin_type = PluginType::getEnum($plugin_type);
            $class = PluginType::getPluginClass($plugin_type, $this, $options);
        }
        
        if (!isset($class) && $throw_ex) {
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
        $disk = \Storage::disk(Define::DISKNAME_ADMIN);
        $adapter = $disk->getDriver()->getAdapter();
        return $adapter->getPluginFullPath($this, ...$pass_array);
    }

    /**
     * call require
     *
     * @param [type] $pathDir
     * @return void
     */
    public function requirePlugin($fullPathDir)
    {
        // call plugin
        $plugin_paths = \File::allFiles($fullPathDir);
        foreach ($plugin_paths as $plugin_path) {
            $pathinfo = pathinfo($plugin_path);
            if ($pathinfo['extension'] != 'php') {
                continue;
            }
            // if blade, not require
            if (strpos($pathinfo['basename'], 'blade.php') !== false) {
                continue;
            }
            require_once($plugin_path);
        }
    }
    
    //Check all plugins satisfied take out from function getPluginByTableId
    //If calling event is not button, then call execute function of this plugin
    //Because namspace can't contains specifies symbol
    /**
     * @param null $event
     */
    public static function pluginPreparing($plugins, $event = null, $options = [])
    {
        $pluginCalled = false;
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // if $plugin_types is not trigger, continue
                if (!$plugin->matchPluginType(PluginType::TRIGGER)) {
                    continue;
                }
                $event_triggers = array_get($plugin, 'options.event_triggers', []);
                $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                
                $class = $plugin->getClass(PluginType::TRIGGER, $options);
                if (in_array($event, $event_triggers) && !in_array($event, $event_triggers_button)) {
                    $pluginCalled = $class->execute();
                    if ($pluginCalled) {
                        admin_toastr('Plugin called: '.$event);
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
    public static function pluginPreparingButton($plugins, $event = null)
    {
        if(empty($plugins)){
            return [];
        }

        $buttonList = [];
        foreach ($plugins as $plugin) {
            // get plugin_types
            $plugin_types = array_get($plugin, 'plugin_types');
            foreach ($plugin_types as $plugin_type) {
                switch ($plugin_type) {
                    case PluginType::DOCUMENT:
                        $event_triggers_button = ['form_menubutton_show'];
                        if (in_array($event, $event_triggers_button)) {
                            $buttonList[] = [
                                'plugin_type' => $plugin_type,
                                'plugin' => $plugin,
                            ];
                        }
                        break;
                    case PluginType::TRIGGER:
                        $event_triggers = $plugin->options['event_triggers'];
                        $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                        if (in_array($event, $event_triggers) && in_array($event, $event_triggers_button)) {
                            $buttonList[] = [
                                'plugin_type' => $plugin_type,
                                'plugin' => $plugin,
                            ];
                        }
                        break;
                }
            }
        }
        
        return $buttonList;
    }

    /**
     * @param $plugins
     * @return array
     */
    public static function pluginPreparingImport($plugins)
    {
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
     * Get plugin pages by selecting plugin_type
     */
    public static function getByPluginTypes($plugin_types, $getAsClass = false)
    {
        return static::getPluginPublicSessions($plugin_types, $getAsClass);
    }

    /**
     * Get plugin page object model
     *
     * @return void
     */
    public static function getPluginPages()
    {
        return static::getPluginPublicSessions([PluginType::PAGE], true);
    }

    /**
     * Get plugin scripts and styles
     *
     * @return void
     */
    public static function getPluginPublics()
    {
        return static::getPluginPublicSessions([PluginType::SCRIPT, PluginType::STYLE], true);
    }

    /**
     * Get plugin sessions
     *
     * @return void
     */
    protected static function getPluginPublicSessions($targetPluginTypes, $getAsClass = false)
    {
        $plugins = static::getPluginsReqSession();
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

            foreach($targetPluginTypes as $targetPluginType){
                $class = $plugin->getClass($targetPluginType, ['throw_ex' => false]);
                if(isset($class)){
                    return $class;
                }
            }
        })->filter();
    }

    protected static function getPluginsReqSession()
    {
        // get plugin page's
        return System::requestSession(Define::SYSTEM_KEY_SESSION_PLUGINS, function () {
            // get plugin
            $plugins = Plugin::allRecords(function ($plugin) {
                if (!boolval(array_get($plugin, 'active_flg'))) {
                    return false;
                }
                
                return true;
            });

            return collect($plugins);
        });
    }
    
    /**
     * Get plugin page model using request uri
     *
     * @return void
     */
    public static function getPluginPageModel()
    {
        // get namespace
        $patterns = ['@plugins/([^/\?]+)@', '@dashboardbox/plugin/([^/\?]+)@'];
        foreach ($patterns as $pattern) {
            preg_match($pattern, request()->url(), $matches);

            if (!isset($matches) || count($matches) <= 1) {
                continue;
            }
    
            $pluginName = $matches[1];
            
            // get target plugin
            $plugin = static::getPluginsReqSession()->first(function ($plugin) use ($pluginName) {
                return $plugin->matchPluginType(Plugintype::PLUGIN_TYPE_PUBLIC_CLASS())
                    && (
                        pascalize(array_get($plugin, 'plugin_name')) == pascalize($pluginName)
                        || $plugin->getOption('uri') == $pluginName
                    )
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
     * Get route uri for page
     *
     * @return void
     */
    public function getRouteUri($endpoint = null)
    {
        return url_join('plugins', $this->getOptionUri(), $endpoint);
    }

    /**
     * Get option uri.
     * set snake_case.
     *
     * @return void
     */
    public function getOptionUri()
    {
        $uri = $this->getOption('uri');
        if(!isset($uri)){
            $uri = array_get($this, 'plugin_name');
        }
        return snake_case($uri);
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
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    
    public function getCustomOption($key, $default = null)
    {
        return $this->getJson('custom_options', $key, $default);
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

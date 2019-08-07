<?php

namespace Exceedone\Exment\Model;

use DB;
use Exceedone\Exment\Enums\DocumentType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Services\Plugin\PluginDocumentDefault;
use Carbon\Carbon;

class Plugin extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;

    protected $casts = ['options' => 'json', 'custom_options' => 'json'];

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

    public static function getFieldById($plugin_id, $field_name)
    {
        return DB::table('plugins')->where('id', $plugin_id)->value($field_name);
    }

    //Get plugin by custom_table name
    //Where active_flg = 1 and target_tables contains custom_table id
    /**
     * @param $id
     * @return mixed
     */
    public static function getPluginsByTable($table_name)
    {
        // execute query
        return static::where('active_flg', '=', 1)
            ->whereIn('plugin_type', [PluginType::TRIGGER, PluginType::DOCUMENT])
            ->whereJsonContains('options->target_tables', $table_name)
            ->get()
            ;
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
        return static::where('plugin_type', PluginType::BATCH)
            ->where('active_flg', 1)
            ->whereIn('options->batch_hour', [strval($hh), $hh])
            // only get batch_cron is null
            ->whereNull('options->batch_cron')
            ->get();
    }

    /**
     * Get Batches filtering has Cron
     *
     * @return void
     */
    public static function getCronBatches()
    {
        return static::where('plugin_type', PluginType::BATCH)
            ->where('active_flg', 1)
            ->whereNotNull('options->batch_cron')
            ->get();
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
    public function getClass($options = [])
    {
        $options = array_merge([
            'custom_table' => null,
            'id' => null,
        ], $options);

        $classname = $this->getNameSpace('Plugin');
        $fuleFullPath = $this->getFullPath('Plugin.php');

        if (\File::exists($fuleFullPath) && class_exists($classname)) {
            switch (array_get($this, 'plugin_type')) {
                case PluginType::DOCUMENT:
                case PluginType::TRIGGER:
                    $class = new $classname($this, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
                    
                case PluginType::BATCH:
                    $class = new $classname($this);
                    break;
            }
        } else {
            // set default class
            switch (array_get($this, 'plugin_type')) {
                case PluginType::DOCUMENT:
                    $class = new PluginDocumentDefault($this, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
            }
        }

        if (!isset($class)) {
            throw new \Exception('plugin not found');
        }

        return $class;
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
        return path_join('plugins', ...$pluginPath);
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
    public function requirePlugin($fullPathDir){
        // call plugin
        $plugin_paths = \File::allFiles($fullPathDir);
        foreach($plugin_paths as $plugin_path){
            if(pathinfo($plugin_path)['extension'] != 'php'){
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
    public static function pluginPreparing($plugins, $event = null)
    {
        $pluginCalled = false;
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // get plugin_type
                $plugin_type = array_get($plugin, 'plugin_type');
                // if $plugin_type is not trigger, continue
                if ($plugin_type != PluginType::TRIGGER) {
                    continue;
                }
                $event_triggers = array_get($plugin, 'options.event_triggers', []);
                $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                
                $class = $plugin->getClass();
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
        $buttonList = [];
        if (count($plugins) > 0) {
            foreach ($plugins as $plugin) {
                // get plugin_type
                $plugin_type = array_get($plugin, 'plugin_type');
                switch ($plugin_type) {
                    case PluginType::DOCUMENT:
                        $event_triggers_button = ['form_menubutton_show'];
                        if (in_array($event, $event_triggers_button)) {
                            array_push($buttonList, $plugin);
                        }
                        break;
                    case PluginType::TRIGGER:
                        $event_triggers = $plugin->options['event_triggers'];
                        $event_triggers_button = ['grid_menubutton','form_menubutton_create','form_menubutton_edit','form_menubutton_show'];
                        if (in_array($event, $event_triggers) && in_array($event, $event_triggers_button)) {
                            array_push($buttonList, $plugin);
                        }
                    break;
                }
            }
        }
        return $buttonList;
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
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

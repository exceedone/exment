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

    protected $casts = ['options' => 'json'];

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
    public static function batches(){
        $now = Carbon::now();
        $hh = $now->hour;
        return static::where('plugin_type', PluginType::BATCH)
            ->where('active_flg', 1)
            ->whereIn('options->batch_hour', [strval($hh), $hh])
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
    public function getClass($options = []){
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
                    $class = new PluginDocumentDefault($plugin, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
            }
        }

        if(!isset($class)){
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
     * Get plugin  fullpath.
     * if $pass_array is  empty, return plugin folder full path.
     */
    public function getFullPath(...$pass_array)
    {
        $pluginBasePath = app_path("Plugins");
        if (!\File::exists($pluginBasePath)) {
            \File::makeDirectory($pluginBasePath, 0775);
        }

        $pluginPath = path_join($pluginBasePath, pascalize(preg_replace('/\s+/', '', $this->plugin_name)));
        if (!\File::exists($pluginPath)) {
            \File::makeDirectory($pluginPath, 0775);
        }

        if (count($pass_array) > 0) {
            $pluginPath = array_merge(
                [$pluginPath],
                $pass_array
            );
        } else {
            $pluginPath = [$pluginPath];
        }
        return path_join(...$pluginPath);
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($model) {
            $model->prepareJson('options');
        });
    }
}

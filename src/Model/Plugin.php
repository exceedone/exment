<?php

namespace Exceedone\Exment\Model;

use DB;
use Exceedone\Exment\Enums\DocumentType;

class Plugin extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\DatabaseJsonTrait;

    protected $casts = ['options' => 'json'];

    public static function getFieldById($plugin_id, $field_name)
    {
        return DB::table('plugins')->where('id', $plugin_id)->value($field_name);
    }

    /**
     * Get document type
     */
    public function getDocumentType()
    {
        return array_get($this->options, 'document_type', DocumentType::EXCEL);
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

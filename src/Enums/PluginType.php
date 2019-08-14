<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Services\Plugin\PluginDocumentDefault;

class PluginType extends EnumBase
{
    public const TRIGGER = '0';
    public const PAGE = '1';
    public const API = '2';
    public const DOCUMENT = '3';
    public const BATCH = '4';
    public const DASHBOARD = '5';
    public const IMPORT = '6';

    public static function getRequiredString()
    {
        return 'trigger,page,api,dashboard,batch,document,import';
    }

    /**
     * Get plugin class
     *
     * @param [type] $plugin
     * @param array $options
     * @return void
     */
    public function getPluginClass($plugin, $options = []){
        $options = array_merge([
            'custom_table' => null,
            'id' => null,
        ], $options);

        $classShortName = $this->getPluginClassShortName($plugin);
        $classname = $plugin->getNameSpace($classShortName);
        $fuleFullPath = $plugin->getFullPath($classShortName . '.php');

        if (\File::exists($fuleFullPath) && class_exists($classname)) {
            switch ($this) {
                case PluginType::DOCUMENT:
                case PluginType::TRIGGER:
                    $class = new $classname($plugin, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
                    
                case PluginType::BATCH:
                case PluginType::PAGE:
                    $class = new $classname($plugin);
                    break;

                case PluginType::IMPORT:
                    $class = new $classname($plugin, array_get($options, 'custom_table'), array_get($options, 'file'));
                    break;
            }
        } else {
            // set default class
            switch ($this) {
                case PluginType::DOCUMENT:
                    $class = new PluginDocumentDefault($plugin, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
            }
        }

        return $class ?? null;
    }

    public function getPluginClassShortName($plugin){
        // if($this == PluginType::PAGE){
        //     return array_get($plugin, 'options.controller');
        // }

        return 'Plugin';
    }
}

<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Services\Plugin\PluginDocumentDefault;
use Exceedone\Exment\Services\Plugin\PluginScriptDefault;
use Exceedone\Exment\Services\Plugin\PluginStyleDefault;

class PluginType extends EnumBase
{
    public const TRIGGER = '0';
    public const PAGE = '1';
    public const API = '2';
    public const DOCUMENT = '3';
    public const BATCH = '4';
    public const DASHBOARD = '5';
    public const IMPORT = '6';
    public const SCRIPT = '7';
    public const STYLE = '8';
    
    public static function getRequiredString()
    {
        return 'trigger,page,api,dashboard,batch,document,import,script,style';
    }

    /**
     * Get plugin class
     *
     * @param [type] $plugin
     * @param array $options
     * @return void
     */
    public function getPluginClass($plugin, $options = [])
    {
        $options = array_merge([
            'custom_table' => null,
            'id' => null,
        ], $options);

        $classShortNames = $this->getPluginClassShortNames();
        foreach($classShortNames as $classShortName){
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

                break;
            } 
        }

        if(!isset($class)) {
            // set default class
            switch ($this) {
                case PluginType::DOCUMENT:
                    $class = new PluginDocumentDefault($plugin, array_get($options, 'custom_table'), array_get($options, 'id'));
                    break;
                case PluginType::SCRIPT:
                    $class = new PluginScriptDefault($plugin);
                    break;
                case PluginType::STYLE:
                    $class = new PluginStyleDefault($plugin);
                    break;
            }
        }

        return $class ?? null;
    }

    protected function getPluginClassShortNames()
    {
        return ['Plugin' . pascalize(strtolower($this->getKey())), 'Plugin'];
    }

    public function isPluginTypeUri()
    {
        return in_array($this, [static::STYLE, static::SCRIPT, static::PAGE]);
    }
}

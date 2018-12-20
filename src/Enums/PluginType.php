<?php

namespace Exceedone\Exment\Enums;

class PluginType extends EnumBase
{
    public const TRIGGER = 0;
    public const PAGE = 1;
    public const API = 2;
    public const DOCUMENT = 3;
    public const BATCH = 4;
    public const DASHBOARD = 5;

    public static function getPluginType($plugin_type){
        if(is_numeric($plugin_type)){
            if(static::isValid($plugin_type)){
                return new self($plugin_type);
            }
            return null;
        }
        switch ($plugin_type) {
            case 'trigger':
                return PluginType::TRIGGER;
            case 'page':
                return PluginType::PAGE;
            case 'api':
                return PluginType::API;
            case 'document':
                return PluginType::DOCUMENT;
            case 'batch':
                return PluginType::BATCH;
            case 'dashboard':
                return PluginType::DASHBOARD;
            default:
                return null;
        }
    }
    public static function getRequiredString(){
        return 'trigger,page,api,dashboard,batch,document';
    }
}

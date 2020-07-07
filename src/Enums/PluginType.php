<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Services\Plugin\PluginDocumentDefault;
use Exceedone\Exment\Services\Plugin\PluginPublicDefault;

/**
 * Plugin Type.
 *
 * @method static PluginType TRIGGER()
 * @method static PluginType PAGE()
 * @method static PluginType API()
 * @method static PluginType DOCUMENT()
 * @method static PluginType BATCH()
 * @method static PluginType DASHBOARD()
 * @method static PluginType IMPORT()
 * @method static PluginType SCRIPT()
 * @method static PluginType STYLE()
 * @method static PluginType VALIDATOR()
 * @method static PluginType EXPORT()
 * @method static PluginType BUTTON()
 * @method static PluginType EVENT()
 */
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
    public const VALIDATOR = '9';
    public const EXPORT = '10';
    public const BUTTON = '11';
    public const EVENT = '12';
    
    /**
     *
     * @return array
     */
    public static function PLUGIN_TYPE_PUBLIC_CLASS()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::SCRIPT,
            static::STYLE,
            static::API,
        ];
    }

    /**
     *
     * @return array
     */
    public static function PLUGIN_TYPE_PLUGIN_PAGE()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::API,
        ];
    }

    /**
     *
     * @return array
     */
    public static function PLUGIN_TYPE_PLUGIN_USE_VIEW()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::BUTTON,
        ];
    }

    /**
     *
     * @return array
     */
    public static function PLUGIN_TYPE_CUSTOM_TABLE()
    {
        return [
            static::TRIGGER,
            static::DOCUMENT,
            static::IMPORT,
            static::EXPORT,
            static::VALIDATOR,
            static::EVENT,
            static::BUTTON,
        ];
    }

    /**
     * Use plugin permission
     *
     * @return array
     */
    public static function PLUGIN_TYPE_FILTER_ACCESSIBLE()
    {
        return [
            static::PAGE,
            static::TRIGGER,
            static::DOCUMENT,
            static::API,
            static::DASHBOARD,
            static::EXPORT,
            static::IMPORT,
            static::BUTTON,
        ];
    }

    /**
     * Use plugin with button
     *
     * @return array
     */
    public static function PLUGIN_TYPE_BUTTON()
    {
        return [
            static::TRIGGER,
            static::DOCUMENT,
            static::BUTTON,
        ];
    }

    /**
     * Use plugin with event
     *
     * @return array
     */
    public static function PLUGIN_TYPE_EVENT()
    {
        return [
            static::TRIGGER,
            static::EVENT,
        ];
    }

    /**
     * Use plugin with URL
     *
     * @return array
     */
    public static function PLUGIN_TYPE_URL()
    {
        return [
            static::API,
            static::PAGE,
        ];
    }

    /**
     * Get plugin class using plugin type
     *
     * @param [type] $plugin
     * @param array $options
     * @return null|mixed
     */
    public static function getPluginClass($plugin_type, $plugin, $options = [])
    {
        $options = array_merge([
            'custom_table' => null,
            'custom_value' => null,
            'dashboard_box' => null,
            'id' => null,
            'as_setting' => false,
        ], $options);

        // get class short name.
        $classShortName = static::getPluginClassShortName($plugin_type, $plugin, $options);

        $classname = $plugin->getNameSpace($classShortName);
        $fuleFullPath = $plugin->getFullPath($classShortName . '.php');
    
        if (\File::exists($fuleFullPath) && class_exists($classname)) {
            // if only one record, set $plugin_type
            if (count($plugin->plugin_types) == 1) {
                $plugin_type = $plugin->plugin_types[0];
            }
            // else if as settingm return as setting class
            elseif (boolval($options['as_setting'])) {
                return new $classname($plugin);
            }

            switch ($plugin_type) {
                case PluginType::DOCUMENT:
                case PluginType::TRIGGER:
                case PluginType::BUTTON:
                case PluginType::EVENT:
                    $custom_value = !is_null($options['custom_value']) ? $options['custom_value'] : $options['id'];
                    return new $classname(
                        $plugin,
                        array_get($options, 'custom_table'),
                        $custom_value,
                        [
                            'workflow_action' => array_get($options, 'workflow_action'),
                            'notify' => array_get($options, 'notify'),
                            'selected_custom_values' => array_get($options, 'selected_custom_values'),
                        ]
                    );
                case PluginType::BATCH:
                case PluginType::PAGE:
                case PluginType::API:
                    return new $classname($plugin);
                case PluginType::DASHBOARD:
                    return new $classname($plugin, array_get($options, 'dashboard_box'));
                case PluginType::IMPORT:
                    return new $classname($plugin, array_get($options, 'custom_table'), array_get($options, 'file'));
                case PluginType::EXPORT:
                    return new $classname($plugin, array_get($options, 'custom_table'));
                case PluginType::VALIDATOR:
                    $custom_value = !is_null($options['custom_value']) ? $options['custom_value'] : $options['id'];
                    return new $classname($plugin, array_get($options, 'custom_table'), $custom_value, array_get($options, 'input_value'));
            }
        }

        // set default class
        switch ($plugin_type) {
            case PluginType::DOCUMENT:
                return new PluginDocumentDefault($plugin, array_get($options, 'custom_table'), array_get($options, 'id'));
            case PluginType::SCRIPT:
            case PluginType::STYLE:
                return new PluginPublicDefault($plugin);
        }

        return null;
    }

    /**
     * Get plugin short class name
     *
     * @param mixed $plugin_type
     * @param array $options
     * @return string
     */
    public static function getPluginClassShortName($plugin_type, $plugin, $options = [])
    {
        $options = array_merge([
            'as_setting' => false,
        ], $options);

        // plugin_types is multiple
        if (count($plugin->plugin_types) > 1) {
            if (boolval($options['as_setting'])) {
                return 'PluginSetting';
            }

            return 'Plugin' . pascalize(strtolower($plugin_type->getKey()));
        }

        // if single
        return 'Plugin';
    }
}

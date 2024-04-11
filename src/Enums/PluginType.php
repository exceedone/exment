<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\Plugin;
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
 * @method static PluginType VIEW()
 * @method static PluginType CRUD()
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
    public const VIEW = '13';
    public const CRUD = '14';

    /**
     * Plugin type. Can call from endpoint.
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
            static::VIEW,
            static::CRUD,
        ];
    }

    /**
     * plugin page types. Needs Page's endpoint.
     * @return array
     */
    public static function PLUGIN_TYPE_PLUGIN_PAGE()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::API,
            static::VIEW,
            static::CRUD,
        ];
    }

    /**
     * plugin show menu. Needs Page's endpoint.
     * @return array
     */
    public static function PLUGIN_TYPE_SHOW_MENU()
    {
        return [
            static::PAGE,
            static::CRUD,
        ];
    }

    /**
     * Get plugin scripts and styles. Needs script and css endpoint, and read public file.
     * @return array
     */
    public static function PLUGIN_TYPE_SCRIPT_STYLE()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::SCRIPT,
            static::STYLE,
            static::VIEW,
        ];
    }

    /**
     * plugin types. Can read resource view.
     * @return array
     */
    public static function PLUGIN_TYPE_PLUGIN_USE_VIEW()
    {
        return [
            static::PAGE,
            static::DASHBOARD,
            static::BUTTON,
            static::VIEW,
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
            static::VIEW,
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
            static::CRUD,
            static::VIEW,
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
            static::CRUD,
        ];
    }


    /**
     * Get plugin class using plugin type
     *
     * @param string $plugin_type
     * @param Plugin $plugin
     * @param array $options
     * @return mixed
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

        // get class name.
        $classname = static::getPluginClassName($plugin_type, $plugin, $options);

        if (!is_null($classname)) {
            // if only one record, set $plugin_type
            if (count($plugin->plugin_types) == 1) {
                $plugin_type = $plugin->plugin_types[0];
            }
            // else if as setting return as setting class
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
                            'event_type' => array_get($options, 'event_type'),
                            'page_type' => array_get($options, 'page_type'),
                            'is_modal' => array_get($options, 'is_modal'),
                            'force_delete' => array_get($options, 'force_delete'),
                        ]
                    );
                case PluginType::BATCH:
                    return new $classname(
                        $plugin,
                        [
                            'command_options' => array_get($options, 'command_options')
                        ]
                    );
                case PluginType::PAGE:
                case PluginType::API:
                case PluginType::CRUD:
                    return new $classname($plugin);
                case PluginType::DASHBOARD:
                    return new $classname($plugin, array_get($options, 'dashboard_box'));
                case PluginType::IMPORT:
                    return new $classname($plugin, array_get($options, 'custom_table'), array_get($options, 'file'));
                case PluginType::EXPORT:
                    return new $classname($plugin, array_get($options, 'custom_table'));
                case PluginType::VALIDATOR:
                    $custom_value = !is_null($options['custom_value']) ? $options['custom_value'] : $options['id'];
                    return new $classname($plugin, array_get($options, 'custom_table'), $custom_value, $options);
                case PluginType::VIEW:
                    return new $classname($plugin, array_get($options, 'custom_table'), array_get($options, 'custom_view'));
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
     * Get plugin class name
     *
     * @param mixed $plugin_type
     * @param array $options
     * @return ?string
     */
    public static function getPluginClassName($plugin_type, $plugin, $options = []): ?string
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
            return $classname;
        }

        // set default class
        switch ($plugin_type) {
            case PluginType::DOCUMENT:
                return PluginDocumentDefault::class;
            case PluginType::SCRIPT:
            case PluginType::STYLE:
                return PluginPublicDefault::class;
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

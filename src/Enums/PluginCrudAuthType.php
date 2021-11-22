<?php

namespace Exceedone\Exment\Enums;

/**
 * PluginCrudAuthType.
 *
 * @method static PluginCrudAuthType NONE()
 * @method static PluginCrudAuthType KEY()
 * @method static PluginCrudAuthType OAUTH()
 */
class PluginCrudAuthType extends EnumBase
{
    public const NONE = '';
    public const KEY = 'key';
    public const OAUTH = 'oauth';
}

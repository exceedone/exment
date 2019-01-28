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

    public static function getRequiredString()
    {
        return 'trigger,page,api,dashboard,batch,document';
    }
}

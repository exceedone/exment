<?php

namespace Exceedone\Exment\Enums;

/**
 * Share Target Type.
 *
 * @method static ShareTargetType DEFAULT()
 * @method static ShareTargetType DASHBOARD()
 * @method static ShareTargetType VIEW()
 */
class ShareTargetType extends EnumBase
{
    public const DEFAULT = null;
    public const DASHBOARD = '_dashboard';
    public const VIEW = '_custom_view';
}

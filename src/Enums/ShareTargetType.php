<?php

namespace Exceedone\Exment\Enums;

/**
 * Share Target Type.
 *
 * @method static ErrorCode DEFAULT()
 * @method static ErrorCode DASHBOARD()
 * @method static ErrorCode VIEW()
 */
class ShareTargetType extends EnumBase
{
    public const DEFAULT = null;
    public const DASHBOARD = '_dashboard';
    public const VIEW = '_custom_view';
}

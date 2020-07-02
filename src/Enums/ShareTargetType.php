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
    const DEFAULT = null;
    const DASHBOARD = '_dashboard';
    const VIEW = '_custom_view';
}

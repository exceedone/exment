<?php

namespace Exceedone\Exment\Enums;

class NotifyAction extends EnumBase
{
    const EMAIL = "1";
    const SHOW_PAGE = "2";
    const SLACK = "3";
    const MICROSOFT_TEAMS = "4";

    public static function isChatMessage($notify_actions)
    {
        if (!isset($notify_actions)) {
            return false;
        }

        if (!is_array($notify_actions)) {
            $notify_actions = [$notify_actions];
        }

        foreach ($notify_actions as $notify_action) {
            if (in_array($notify_action, [static::SLACK, static::MICROSOFT_TEAMS])) {
                return true;
            }
        }

        return false;
    }
}

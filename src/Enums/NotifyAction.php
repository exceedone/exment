<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

class NotifyAction extends EnumBase
{
    public const EMAIL = "1";
    public const SHOW_PAGE = "2";
    public const SLACK = "3";
    public const MICROSOFT_TEAMS = "4";

    /**
     * Whether this action is chat message.
     *
     * @param string|array $action_setting
     * @return boolean
     */
    public static function isChatMessage($action_setting): bool
    {
        if (is_nullorempty($action_setting)) {
            return false;
        }
        $notify_action = is_array($action_setting) ? array_get($action_setting, 'notify_action') : $action_setting;

        if (in_array($notify_action, [static::SLACK, static::MICROSOFT_TEAMS])) {
            return true;
        }

        return false;
    }


    /**
     * Whether this action is targeted user message
     *
     * @param string|array $action_setting
     * @return boolean
     */
    public static function isUserTarget($action_setting): bool
    {
        if (is_nullorempty($action_setting)) {
            return false;
        }
        $notify_action = is_array($action_setting) ? array_get($action_setting, 'notify_action') : $action_setting;

        if (in_array($notify_action, [static::EMAIL, static::SHOW_PAGE])) {
            return true;
        }
        return false;
    }

    public static function getColumnGettingOptions($notify_action)
    {
        switch ($notify_action) {
            case static::EMAIL:
                return [
                    'get_email' => true,
                    'get_select_table_email' => true,
                ];

            case static::SHOW_PAGE:
                return [
                    'get_user' => true,
                    //'get_organization' => true,
                ];

            case static::SLACK:
                $system_slack_user_column = System::system_slack_user_column();
                if (is_nullorempty($system_slack_user_column)) {
                    return [];
                }
                return [
                    'get_user' => true,
                    //'get_organization' => true,
                ];

            case static::MICROSOFT_TEAMS:
                return [
                ];
        }

        return [];
    }
}

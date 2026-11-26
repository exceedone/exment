<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\System;

class NotifyAction extends EnumBase
{
    public const EMAIL = "1";
    public const SHOW_PAGE = "2";
    public const SLACK = "3";
    public const MICROSOFT_TEAMS = "4";
    public const LINE = "5";
    // a person's Teams chat, through a Teams workflow (ISS02863)
    public const TEAMS_PERSONAL = "6";
    // the record as JSON to any URL, signed (ISS02912)
    public const WEBHOOK = "7";

    /**
     * Whether this action is chat message.
     *
     * @param string|array $action_setting
     * @return boolean
     */
    // @phpstan-ignore-next-line
    public static function isChatMessage($action_setting): bool
    {
        if (is_nullorempty($action_setting)) {
            return false;
        }
        $notify_action = is_array($action_setting) ? array_get($action_setting, 'notify_action') : $action_setting;

        // a webhook is sent once to its URL, as a chat message is (not to each user)
        if (in_array($notify_action, [static::SLACK, static::MICROSOFT_TEAMS, static::WEBHOOK])) {
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
    // @phpstan-ignore-next-line
    public static function isUserTarget($action_setting): bool
    {
        if (is_nullorempty($action_setting)) {
            return false;
        }
        $notify_action = is_array($action_setting) ? array_get($action_setting, 'notify_action') : $action_setting;

        if (in_array($notify_action, [static::EMAIL, static::SHOW_PAGE, static::LINE, static::TEAMS_PERSONAL])) {
            return true;
        }
        return false;
    }

    // @phpstan-ignore-next-line
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

            case static::LINE:
            case static::TEAMS_PERSONAL:
                return [
                    'get_user' => true,
                ];
        }

        return [];
    }

    /**
     * The choices of the notify action: Teams (personal) only while its feature is used.
     *
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        $options = static::transKeyArray('notify.notify_action_options');
        if (!\Exceedone\Exment\Services\Features::enabled(\Exceedone\Exment\Services\Features::TEAMS_NOTIFY)) {
            unset($options[static::TEAMS_PERSONAL]);
        }
        if (!\Exceedone\Exment\Services\Features::enabled(\Exceedone\Exment\Services\Features::WEBHOOK_NOTIFY)) {
            unset($options[static::WEBHOOK]);
        }
        return $options;
    }
}

<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\UserSetting as UserSettingKey;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Config;

/**
 * How an item of the left menu with items under it opens (ISS02958):
 *
 * - "flyout": its items are shown in a panel to the side, on hover or click, also with the menu
 *   folded, and with the keyboard (vendor/exment/js/menu-flyout.js)
 * - "inline": they open below the item, as before
 *
 * The site has a default (system setting "menu_open", "flyout" at first) and each user may choose
 * the other in the personal settings; the user's choice wins. On a touch screen or a narrow one
 * (the menu is a drawer there) the items always open below: the script only acts with a fine
 * pointer and a wide screen.
 */
class MenuOpen
{
    public const FLYOUT = 'flyout';
    public const INLINE = 'inline';

    /** The choice of the user (empty: the site default). */
    public const SETTING_KEY = UserSettingKey::USER_SETTING . '.menu_open';

    /** The class of <body> in flyout mode. */
    public const BODY_CLASS = 'exm-menu-flyout';

    /**
     * @return array<string, string> the choices, with their names
     */
    public static function options(): array
    {
        return [
            static::FLYOUT => exmtrans('system.menu_open_options.flyout'),
            static::INLINE => exmtrans('system.menu_open_options.inline'),
        ];
    }

    public static function isValid(?string $value): bool
    {
        return in_array($value, [static::FLYOUT, static::INLINE], true);
    }

    /** The default of the site. */
    public static function site(): string
    {
        $value = System::menu_open();
        return static::isValid($value) ? $value : static::FLYOUT;
    }

    /** What the login user uses: their own choice, or the default of the site. */
    public static function current(): string
    {
        $user = \Exment::user();
        if (isset($user)) {
            $value = $user->getSettingValue(static::SETTING_KEY);
            if (static::isValid($value)) {
                return $value;
            }
        }
        return static::site();
    }

    /** Save the choice of the login user (not a valid one, or the default of the site: none). */
    public static function save(?string $value): void
    {
        $user = \Exment::user();
        if (is_null($user)) {
            return;
        }
        $user->setSettingValue(static::SETTING_KEY, static::isValid($value) ? $value : null);
    }

    /** The class of <body> (config admin.layout) for the page. */
    public static function applyLayout(): void
    {
        $layout = array_values(array_diff((array) config('admin.layout', []), [static::BODY_CLASS]));
        if (static::current() === static::FLYOUT) {
            $layout[] = static::BODY_CLASS;
        }
        Config::set('admin.layout', $layout);
    }
}

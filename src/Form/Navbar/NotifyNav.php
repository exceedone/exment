<?php

namespace Exceedone\Exment\Form\Navbar;

use Illuminate\Contracts\Support\Renderable;

class NotifyNav implements Renderable
{
    /** How often the bell asks, in seconds (EXMENT_NOTIFY_NAVBAR_INTERVAL) (ISS02824). */
    public const DEFAULT_INTERVAL = 60;

    /** The shortest interval: a wrong .env must not make every browser ask every second. */
    public const MIN_INTERVAL = 30;

    public static function interval(): int
    {
        $interval = config('exment.notify_navbar_interval', static::DEFAULT_INTERVAL);
        if (!is_numeric($interval)) {
            return static::DEFAULT_INTERVAL;
        }
        return max((int) $interval, static::MIN_INTERVAL);
    }

    /**
     * Who is logged in, as the javascript keeps the answers of the polls in localStorage
     * (navbar_poll.js): the answer of one user (or one Exment of the same host) is never drawn
     * for another. A hash: the page does not need the id.
     */
    public static function userKey(): string
    {
        return md5(admin_url() . '|' . (string) \Exment::getUserId());
    }

    public function render()
    {
        if (config('exment.notify_navbar', true) === false) {
            return '';
        }

        $no_newitem = exmtrans('notify_navbar.message.no_newitem');
        $list = trans('admin.list');
        $list_url = admin_url('notify_navbar');
        // read all: the notifications stay in the list, greyed (ISS00360)
        $read_all = esc_html(exmtrans('notify_navbar.read_all'));
        $read_all_url = esc_html(admin_url('notify_navbar/batchAll/read'));
        $interval = static::interval();
        $user_key = static::userKey();

       return <<<EOT
            <input id="notify_navbar_noitem" type="hidden" value="$no_newitem" />
            <input id="notify_navbar_interval" type="hidden" value="$interval" data-user="$user_key" />
            <li class="navbar-notify dropdown notifications-menu">
                <a href="javascript:void(0);" 
                class="container-notify dropdown-toggle" 
                data-bs-toggle="dropdown" 
                aria-expanded="false">
                <i class="fa fa-bell"></i>
                </a>

                <ul class="dropdown-menu notifications-menu-dropdown">
                    <li>
                        <!-- inner menu: contains the actual data -->
                        <ul class="menu"></ul>
                    </li>
                    <li class="footer text-center notify-navbar-footer">
                        <a href="javascript:void(0);" class="notify-navbar-read-all" data-url="$read_all_url" style="display:inline-block;width:49%;">$read_all</a>
                        <a href="$list_url" style="display:inline-block;width:49%;">$list</a>
                    </li>
                </ul>
            </li>
            EOT;

    }
}

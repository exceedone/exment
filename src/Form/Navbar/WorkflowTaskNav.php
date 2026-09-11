<?php

namespace Exceedone\Exment\Form\Navbar;

use Illuminate\Contracts\Support\Renderable;

/**
 * Feature 1: navbar icon for the current user's un-actioned workflow tasks.
 * Mirrors NotifyNav (the notification bell): the dropdown list and the
 * unseen-count badge are filled by workflow_task_navbar.js.
 */
class WorkflowTaskNav implements Renderable
{
    /**
     * Default polling interval, in seconds.
     * Deliberately slower than the notification bell (60s): this endpoint scans every
     * pending record of every workflow table, the bell runs two cheap queries.
     */
    const DEFAULT_INTERVAL = 300;

    /**
     * Hard floor, in seconds. A misconfigured .env must not be able to turn every logged-in
     * browser into a request generator against an expensive endpoint.
     */
    const MIN_INTERVAL = 30;

    /**
     * Polling interval to hand to the javascript, in seconds.
     *
     * @return int
     */
    public static function interval(): int
    {
        $interval = config('exment.workflow_task_navbar_interval', static::DEFAULT_INTERVAL);

        // "EXMENT_WORKFLOW_TASK_NAVBAR_INTERVAL=" (left empty) makes config() return null, not the
        // default - config()'s default only applies to a MISSING key. Casting that to int gives 0,
        // which would then clamp to the floor and poll 10x faster than intended. Anything that is
        // not a usable number falls back to the default; only a real, too small number is clamped.
        if (!is_numeric($interval)) {
            return static::DEFAULT_INTERVAL;
        }

        return max((int)$interval, static::MIN_INTERVAL);
    }

    public function render()
    {
        if (!boolval(config('exment.workflow_task_navbar', true))) {
            return '';
        }

        $no_newitem = exmtrans('workflow_task.empty');
        $list = exmtrans('workflow_task.header');
        $list_url = admin_url('workflow_task');
        $interval = static::interval();

        return <<<EOT
        <input id="workflow_task_navbar_noitem" type="hidden" value="$no_newitem" />
        <input id="workflow_task_navbar_interval" type="hidden" value="$interval" />
<li class="navbar-workflow-task dropdown notifications-menu">
    <a href="javascript:void(0);" class="container-workflow-task hidden-xs dropdown-toggle" data-toggle="dropdown" title="$list">
      <i class="fa fa-sitemap"></i>
    </a>

    <ul class="dropdown-menu notifications-menu-dropdown">
        <li>
        <!-- inner menu: contains the actual data -->
        <ul class="menu">
        </ul>
        </li>
        <li class="footer"><a href="$list_url">$list</a></li>
    </ul>
</li>
EOT;
    }
}

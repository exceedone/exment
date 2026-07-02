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
    public function render()
    {
        if (config('exment.workflow_task_navbar', true) === false) {
            return '';
        }

        $no_newitem = exmtrans('workflow_task.empty');
        $list = exmtrans('workflow_task.header');
        $list_url = admin_url('workflow_task');

        return <<<EOT
        <input id="workflow_task_navbar_noitem" type="hidden" value="$no_newitem" />
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

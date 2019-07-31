<?php

namespace Exceedone\Exment\Form\Navbar;

use Illuminate\Contracts\Support\Renderable;

class NotifyNav implements Renderable
{
    public function render()
    {
        if (config('exment.notify_navbar', true) === false) {
            return;
        }
        
        $list = trans('admin.list');
        $list_url = admin_url('notify_navbar');
        return <<<EOT
<li class="navbar-notify dropdown notifications-menu">
    <a href="javascript:void(0);" class="container-notify hidden-xs dropdown-toggle" data-toggle="dropdown">
      <i class="fa fa-bell"></i>
    </a>

    <ul class="dropdown-menu">
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

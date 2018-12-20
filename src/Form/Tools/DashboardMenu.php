<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\DashboardType;
use Encore\Admin\Facades\Admin;

class DashboardMenu
{
    protected $current_dashboard;

    public function __construct($current_dashboard)
    {
        $this->current_dashboard = $current_dashboard;
    }

    public function render()
    {
        $systemdashboards = [];
        $userdashboards = [];

        // get dashboard list
        $dashboards = Dashboard::all();

        foreach ($dashboards as $d) {
            if ($d->dashboard_type == DashboardType::SYSTEM) {
                $systemdashboards[] = $d->toArray();
            } else {
                $userdashboards[] = $d->toArray();
            }
        }

        // setting menu list
        $settings = [];
        //authority check
        //TODO:now system admin. change if user dashboard
        if (Admin::user()->hasPermission(AuthorityValue::SYSTEM)) {
            $settings[] = ['url' => admin_base_paths('dashboard', $this->current_dashboard->id, 'edit'), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.current_dashboard_edit')];
            $settings[] = ['url' => admin_base_paths('dashboard', 'create'), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.create')];
        }
        return view('exment::dashboard.header', [
            'current_dashboard' => $this->current_dashboard,
            'systemdashboards' => $systemdashboards,
            'userdashboards' => $userdashboards,
            'settings' => $settings,
            'base_uri' => admin_base_path('')
        ]);
    }
}

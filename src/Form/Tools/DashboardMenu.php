<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Enums\DashboardType;

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
        $dashboards = Dashboard::get();

        foreach ($dashboards as $d) {
            if ($d->dashboard_type == DashboardType::SYSTEM) {
                $systemdashboards[] = $d->toArray();
            } else {
                $userdashboards[] = $d->toArray();
            }
        }

        // setting menu list
        $settings = [];

        if (Dashboard::hasPermission()) {
            //role check
            $editflg = $this->current_dashboard->hasEditPermission();

            if ($editflg) {
                $settings[] = ['url' => admin_urls('dashboard', $this->current_dashboard->id, 'edit'), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.current_dashboard_edit')];
            }

            $settings[] = ['url' => admin_urls('dashboard', 'create'), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.create')];
        }

        return view('exment::dashboard.header', [
            'current_dashboard' => $this->current_dashboard,
            'systemdashboards' => $systemdashboards,
            'userdashboards' => $userdashboards,
            'settings' => $settings,
            'base_uri' => admin_url('')
        ]);
    }
}

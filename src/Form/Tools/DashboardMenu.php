<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Dashboard;

class DashboardMenu
{
    protected $current_dashboard;

    public function __construct($current_dashboard){
        $this->current_dashboard = $current_dashboard;
    }

    public function render()
    {
        $systemdashboards = [];
        $userdashboards = [];

        // get dashboard list
        $dashboards = Dashboard::all();

        foreach($dashboards as $d){
            if ($d->dashboard_type == Define::VIEW_COLUMN_TYPE_SYSTEM) {
                $systemdashboards[] = $d->toArray();
            }else{
                $userdashboards[] = $d->toArray();
            }
        }

        // setting menu list
        $settings = [];
        //TODO:authority check
        $settings[] = ['url' => admin_base_path(url_join('dashboard', $this->current_dashboard->id, 'edit')), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.current_dashboard_edit')];
        $settings[] = ['url' => admin_base_path(url_join('dashboard', 'create')), 'dashboard_view_name' => exmtrans('dashboard.dashboard_menulist.create')];

        return view('exment::dashboard.header', [
            'current_dashboard' => $this->current_dashboard,
            'systemdashboards' => $systemdashboards, 
            'userdashboards' => $userdashboards,
            'settings' => $settings,
            'base_uri' => admin_base_path('')
        ]);
    }
}

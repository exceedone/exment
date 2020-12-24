<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Tests\Browser\ExmentKitTestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\DashboardBoxType;

class PluginPageTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        // precondition : login success
        $this->login();
    }

    /**
     * display plugin page.
     */
    public function testDisplayPluginPage()
    {
        $this->visit(admin_url('plugins/test_plugin_demo_page'))
                ->seeInElement('h1', '独自ページテスト')
                ->seeInElement('div', 'Laravel')
        ;
    }

    /**
     * setting plugin dashboard.
     */
    public function testSettingDashboard()
    {
        $pre_cnt = Dashboard::count();
        $pre_cnt_box = DashboardBox::count();
        // Create dashbord
        $response = $this->visit(admin_url('dashboard/create'))
                ->seePageIs(admin_url('dashboard/create'))
                ->type('unit test', 'dashboard_view_name')
                ->press('admin-submit')
                ->seePageIs(admin_url(''))
                ->seeInElement('button', 'unit test')
                ->assertEquals($pre_cnt + 1, Dashboard::count());

        $row = Dashboard::orderBy('created_at', 'desc')->first();
        $suuid = array_get($row, 'suuid');
        $param = "?column_no=1&dashboard_box_type=plugin&dashboard_suuid=$suuid&row_no=1";
        $plugin = Plugin::where('plugin_name', 'TestPluginDashboard')->first();

        // Create dashbord box
        $response = $this->visit(admin_url('dashboardbox/create' . $param))
                ->seePageIs(admin_url('dashboardbox/create' . $param))
                ->type('unit test box', 'dashboard_box_view_name')
                ->select($plugin->id, 'options[target_plugin_id]')
                ->press('admin-submit')
                ->seePageIs(admin_url(''))
                ->seeInElement('button', 'unit test')
                ->assertEquals($pre_cnt_box + 1, DashboardBox::count());

    }

    /**
     * display plugin dashboard.
     */
    public function testDisplayDashboard()
    {
        System::clearCache();

        $data = CustomTable::getEloquent('custom_value_edit_all')
                    ->getValueModel()->where('value->user', \Exment::user()->base_user->id)->first();
        $box = DashboardBox::where('dashboard_box_view_name', 'unit test box')->first();

        $integer = $data->getValue('integer');

        $response = $this->get(admin_url('dashboardbox/html/' . $box->suuid));
        $content = $response->response->getContent();
        if(is_json($content)){
            $json = json_decode($content, true);
            $body = array_get($json, 'body');

            $this->assertTrue(strpos($body,"<h4>$integer</h4>") !== false);
        }
    }

    /**
     * delete plugin dashboard.
     */
    public function testDeleteDashboard()
    {
        $pre_cnt = Dashboard::count();
        $pre_cnt_box = DashboardBox::count();
        $dashboard = Dashboard::where('dashboard_view_name', 'unit test')->first();
        // delete dashbord
        $this->delete('/admin/dashboard/'. $dashboard->id);
        $this->assertEquals($pre_cnt - 1, Dashboard::count());
        $this->assertEquals($pre_cnt_box - 1, DashboardBox::count());
    }

    /**
     * test plugin script.
     */
    public function testScriptAddress()
    {
        System::clearCache();

        $response = $this->get(admin_url('data/base_info'));
        $content = $response->response->getContent();
        $this->assertTrue(is_string($content));
        $this->assertTrue(strpos($content,'plugins/test_plugin_script/public/ajaxzip3-source.js') !== false);
        $this->assertTrue(strpos($content,'plugins/test_plugin_script/public/script.js') !== false);
    }

    /**
     * test plugin style.
     */
    public function testStyleCss()
    {
        System::clearCache();

        $response = $this->get(admin_url('data/base_info'));
        $content = $response->response->getContent();
        $this->assertTrue(is_string($content));
        $this->assertTrue(strpos($content,'plugins/test_plugin_style/public/style.css') !== false);
    }
}

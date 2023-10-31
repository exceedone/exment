<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\Installer\InstallService;

class InitializeController extends Controller
{
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request)
    {
        return InstallService::index();
    }

    /**
     * submit
     * @param Request $request
     */
    public function post(Request $request)
    {
        if ($request->get('log_available')) {
            $admin_menu = Menu::where('menu_name', 'admin')->first();
            if ($admin_menu) {
                $order = Menu::where('parent_id', $admin_menu->id)->max('order') + 1;
                $menu_target = CustomTable::getEloquent(SystemTableName::ACCESS_FILE_LOG)->id;
                $log_menu = new Menu();
                $log_menu->parent_id = $admin_menu->id;
                $log_menu->order = $order;
                $log_menu->title = 'アクセスログ';
                $log_menu->icon = 'fa-file';
                $log_menu->uri = SystemTableName::ACCESS_FILE_LOG;
                $log_menu->menu_type = 'table';
                $log_menu->menu_name = SystemTableName::ACCESS_FILE_LOG;
                $log_menu->menu_target = $menu_target;
                $log_menu->save();
            }
        }
        return InstallService::post();
    }
}

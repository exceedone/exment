<?php

namespace App\Plugins\TestPluginDemoPage;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\HasResourceActions;
use Exceedone\Exment\Model\PluginPage;
use Exceedone\Exment\Services\Plugin\PluginPageBase;
use Illuminate\Http\Request;

class Plugin extends PluginPageBase
{
    /**
     * Display a listing of the resource.
     *
     * @return Content|\Illuminate\Http\Response
     */

    public function index()
    {
        return view('exment_test_plugin_demo_page::welcome');
    }
}
<?php

namespace App\Plugins\TestPluginPage;

use Encore\Admin\Layout\Content;
use Exceedone\Exment\Services\Plugin\PluginPageBase;

class Plugin extends PluginPageBase
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */

    public function index()
    {
        return view('exment_test_plugin_page::welcome');
    }
}

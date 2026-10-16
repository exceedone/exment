<?php

namespace App\Plugins\TestPluginPage;

use ExmentAdminCore\Admin\Layout\Content;
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
        return $this->pluginView('welcome');
    }
}

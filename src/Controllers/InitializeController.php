<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
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
        return InstallService::post();
    }
}

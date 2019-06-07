<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exceedone\Exment\Services\Installer\InstallService;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;

class InstallController extends Controller
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

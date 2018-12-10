<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\Auth;
use Exceedone\Exment\Enums\AuthorityValue;

/**
 * Api about target table
 */
class ApiAdminTableController extends AdminControllerTableBase
{
    use ApiTableTrait;
    protected function user(){
        return Admin::user();
    }
}

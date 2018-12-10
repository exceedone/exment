<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\ColumnType;

class ApiAdminController extends AdminControllerBase
{
    use ApiTrait;
    protected function user(){
        return Admin::user();
    }
}

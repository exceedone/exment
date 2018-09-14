<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\CustomTable;

class AdminControllerBase extends Controller
{
    use ExmentControllerTrait;
    /**
     * Check form is new(create) using url.
     */
    protected function isNew()
    {
        $url = url()->current();
        $urls = explode("/", $url);
        // check the url.
        // if end of url is "create", return true
        // if end of url is "edit", return false
        // if neithor, return whether end of url is number.
        if (end($urls) == 'create') {
            return true;
        } elseif (end($urls) == 'edit') {
            return false;
        } else {
            return !ctype_digit(end($urls));
        }
    }

    protected function getColumns(Request $request)
    {
        $id = $request->input('q');
        $options = CustomTable::find($id)->custom_columns()->get(['id', DB::raw('column_view_name as text')]);
        return $options;
    }
}

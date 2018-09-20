<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\CustomTable;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;

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
    
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        return $this->AdminContent($content)->body($this->grid());
    }

    /**
     * Show interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function show(Request $request, $id, Content $content)
    {
        if(method_exists($this, 'detail')){
            $render = $this->detail($id);
        }else{
            $render = $this->detail($id);
        }
        return $this->AdminContent($content)->body($render);
    }

    /**
     * Edit interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function edit(Request $request, $id, Content $content)
    {
        return $this->AdminContent($content)->body($this->form()->edit($id));
    }

    /**
     * Create interface.
     *
     * @param Content $content
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        return $this->AdminContent($content)->body($this->form());
    }
}

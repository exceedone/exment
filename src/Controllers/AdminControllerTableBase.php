<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomForm;
use App\Http\Controllers\Controller;

class AdminControllerTableBase extends Controller
{
    use ExmentControllerTrait;

    protected $custom_table;
    protected $custom_columns;
    protected $custom_view;
    protected $custom_form;

    public function __construct(Request $request)
    {
        $this->custom_table = CustomTable::findByEndpoint();
        
        if (!isset($this->custom_table)) {
            return;
        }

        $this->custom_table->load('custom_columns');
        $this->custom_columns = $this->custom_table->custom_columns;

        getModelName($this->custom_table);

        $this->setFormViewInfo($request);
    }

    protected function getModelNameDV()
    {
        return getModelName($this->custom_table);
    }

    /**
     * validate table_name and id
     * ex. check /admin/column/user/1/edit
     * whether "1" is user's column
     * $isValue: whether
     */
    protected function validateTableAndId($className, $id, $endpoint)
    {
        // get value whether
        $result = true;
        $val = $className::find($id);
        // when not found $val, redirect back
        if (!isset($val)) {
            admin_toastr(exmtrans('common.message.notfound'), 'error');
            $result = false;
        }
        // check same id
        else {
            $id = $this->custom_table->id;
            // if custom relation, check $val->parent_custom_table_id and id
            if (str_contains($className, 'CustomRelation')) {
                if ($val->parent_custom_table_id != $id) {
                    admin_toastr(exmtrans('common.message.wrongdata'), 'error');
                    $result = false;
                }
            } elseif (str_contains($className, 'CustomCopy')) {
                if ($val->from_custom_table_id != $id) {
                    admin_toastr(exmtrans('common.message.wrongdata'), 'error');
                    $result = false;
                }
            }
            // check $val->custom_table_id == $this->custom_table->id
            else {
                if ($val->custom_table_id != $id) {
                    admin_toastr(exmtrans('common.message.wrongdata'), 'error');
                    $result = false;
                }
            }
        }

        if (!$result) {
            Checker::error();
            return false;
        }
        return true;
    }

    /**
     * set view and form info.
     * use session etc
     */
    protected function setFormViewInfo(Request $request)
    {
        // set view
        $this->custom_view = CustomView::getDefault($this->custom_table);

        // set form
        $this->custom_form = CustomForm::getDefault($this->custom_table);
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
    public function show(Request $request, Content $content, $tableKey, $id)
    {
        if (method_exists($this, 'detail')) {
            $render = $this->detail($id);
        } else {
            $render = $this->form($id);
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
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        return $this->AdminContent($content)->body($this->form($id)->edit($id));
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

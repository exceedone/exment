<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomForm;

class AdminControllerTableBase extends AdminControllerBase
{
    protected $custom_table;
    protected $custom_columns;
    protected $custom_view;
    protected $custom_form;
    //protected $custom_form_columns;

    public function __construct(Request $request)
    {
        $this->custom_table = CustomTable::findByEndpoint();
        $this->custom_columns = isset($this->custom_table) ? $this->custom_table->custom_columns : null;

        getModelName($this->custom_table);
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
        // get admin_user
        $admin_user = Admin::user();
        // set view
        $this->custom_view = CustomView::getDefault($this->custom_table);

        // set form
        $this->custom_form = CustomForm::getDefault($this->custom_table);
    }
}

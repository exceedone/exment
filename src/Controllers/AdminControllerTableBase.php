<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\Define;

class AdminControllerTableBase extends AdminControllerBase
{
    protected $custom_table;
    protected $custom_columns;
    protected $custom_view;
    protected $custom_form;
    //protected $custom_form_columns;

    public function __construct(Request $request){
        $this->custom_table = getEndpointTable();
        $this->custom_columns = isset($this->custom_table) ? $this->custom_table->custom_columns : null;

        getModelName($this->custom_table);
    }

    protected function getModelNameDV(){
        return getModelName($this->custom_table);
    }
    /**
     * validate table_name and id
     * ex. check /admin/column/user/1/edit
     * whether "1" is user's column
     * $isValue: whether 
     */
    protected function validateTableAndId($className, $id, $endpoint){
        // get value whether
        $result = true;
        $val = $className::find($id);
        // when not found $val, redirect back
        if(!isset($val)){
            admin_toastr(exmtrans('common.message.notfound'), 'error');
            $result = false;
        }
        // check same id
        else{
            $id = $this->custom_table->id;
            $is_custom_relation = str_contains($className, 'CustomRelation');
            // if custom relation, check $val->parent_custom_table_id and id
            if($is_custom_relation && str_contains($className, 'CustomRelation') && $val->parent_custom_table_id != $id){
                admin_toastr(exmtrans('common.message.wrongdata'), 'error');
                $result = false;
            }
            // check $val->custom_table_id == $this->custom_table->id
            elseif (!$is_custom_relation && $val->custom_table_id != $id) {
                admin_toastr(exmtrans('common.message.wrongdata'), 'error');
                $result = false;
            }
        }

        if(!$result){
            return redirect(admin_url(url_join($endpoint, $this->custom_table->table_name)));
        }
    }

    /**
     * set view and form info.
     * use session etc
     */
    protected function setFormViewInfo(Request $request){
        // get admin_user
        $admin_user = Admin::user();
        // set view
        $this->setView($request, $admin_user);

        // set form
        $this->setForm($request, $admin_user);
    }

    // view --------------------------------------------------
    protected function setView(Request $request, $admin_user){
        // get view using query
        if(!is_null($request->input('view'))){
            $suuid = $request->input('view');
            // if query has view id, set form.
            $this->custom_view = CustomView::findBySuuid($suuid);
            
            // set suuid
            if (!is_null($admin_user)) {
                $admin_user->setSettingValue(implode(".", [Define::USER_SETTING_VIEW, $this->custom_table->table_name]), $suuid);
            }
        }
        // if url doesn't contain view query, get view user setting.
        if(is_null($this->custom_view) && !is_null($admin_user)){
            // get suuid
            $suuid = $admin_user->getSettingValue(implode(".", [Define::USER_SETTING_VIEW, $this->custom_table->table_name]));
            $this->custom_view = CustomView::findBySuuid($suuid);
        }
        // if url doesn't contain view query, get custom view.
        if(is_null($this->custom_view)){
            $this->custom_view = $this->custom_table->custom_views()->first();
        }
        // if form doesn't contain for target table, create view.
        if(is_null($this->custom_view)){
            $this->custom_view = createDefaultView($this->custom_table);
        }

        // if target form doesn't have columns, add columns for search_enabled columns.
        if(is_null($this->custom_view->custom_view_columns) || count($this->custom_view->custom_view_columns) == 0){
            createDefaultViewColumns($this->custom_view);
            // reload and re-get relation
            //$this->custom_view->reload();
        }
    }

    // form --------------------------------------------------
    protected function setForm(Request $request, $admin_user){
        // get form using query
        if(!is_null($request->input('form'))){
            // if query has form id, set form.
            $suuid = $request->input('form');
            $this->custom_form = CustomForm::findBySuuid($suuid);

            // set suuid
            if (!is_null($admin_user)) {
                $admin_user->setSettingValue(implode(".", [Define::USER_SETTING_FORM, $this->custom_table->table_name]), $suuid);
            }
        }
        // if url doesn't contain form query, get form user setting.
        if(is_null($this->custom_form) && !is_null($admin_user)){
            // get suuid
            $suuid = $admin_user->getSettingValue(implode(".", [Define::USER_SETTING_FORM, $this->custom_table->table_name]));
            $this->custom_form = CustomForm::findBySuuid($suuid);
        }

        // if not exists, get first.
        if(is_null($this->custom_form)){
            $this->custom_form = $this->custom_table->custom_forms()->first();
        }
        
        // if form doesn't contain for target table, create form.
        if(is_null($this->custom_form)){
            $form = new CustomForm;
            $form->custom_table_id = $this->custom_table->id;
            $form->form_view_name = exmtrans('custom_form.default_form_name');
            $form->saveOrFail();
            $this->custom_form = $form;

            // Create CustomFormBlock as default
            $form_block = new CustomFormBlock;
            $form_block->form_block_type = Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT;
            $form_block->form_block_target_table_id = $this->custom_table->id;
            $form_block->available = true;
            $this->custom_form->custom_form_blocks()->save($form_block);
        }

        // if target form doesn't have columns, add columns for search_enabled columns.
        if(is_null($this->custom_form->custom_form_columns) || count($this->custom_form->custom_form_columns) == 0){
            $form_columns = [];
            $search_enabled_columns = getSearchEnabledColumns($this->custom_table->table_name);

            // get target block as default.
            $form_block = $this->custom_form->custom_form_blocks()
                ->where('form_block_type', Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT)
                ->firstOrFail();            
            // loop for search_enabled columns, and add form.
            foreach ($search_enabled_columns as $index => $search_enabled_column)
            {
                $form_column = new CustomFormColumn;
                $form_column->custom_form_block_id = $form_block->id;
                $form_column->form_column_type = Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN;
                $form_column->form_column_target_id = array_get($search_enabled_column, 'id');
                $form_column->order = $index+1;
                array_push($form_columns, $form_column);
            }
            $form_block->custom_form_columns()->saveMany($form_columns);
        }
    }
}
<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Illuminate\Http\RedirectResponse;

class CustomRelationController extends AdminControllerTableBase
{
    use ModelForm;

    public function __construct(Request $request){
        parent::__construct($request);
        
        $this->setPageInfo(exmtrans("custom_relation.header"), exmtrans("custom_relation.header"), exmtrans("custom_relation.description"));  
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index()
    {
        return $this->AdminContent(function (Content $content) {
            $content->body($this->grid());
        });
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit($id)
    {
        if (($response = $this->validateTableAndId(CustomRelation::class, $id, 'relation')) instanceof RedirectResponse) {
            return $response;
        }

        return $this->AdminContent(function (Content $content) use ($id) {
            $content->body($this->form($id)->edit($id));
        });
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request)
    {
        return $this->AdminContent(function (Content $content) {
            $content->body($this->form());
        });
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(CustomRelation::class, function (Grid $grid) {
            $grid->column('parent_custom_table.table_name', exmtrans("custom_relation.parent_custom_table_name"))->sortable();
            $grid->column('parent_custom_table.table_view_name', exmtrans("custom_relation.parent_custom_table_view_name"))->sortable();
            $grid->column('child_custom_table.table_name', exmtrans("custom_relation.child_custom_table_name"))->sortable();
            $grid->column('child_custom_table.table_view_name', exmtrans("custom_relation.child_custom_table_view_name"))->sortable();
            $grid->column('relation_type', exmtrans("custom_relation.relation_type"))->sortable()->display(function($relation_type){
                $relation_type_options = getTransArray(Define::RELATION_TYPE, "custom_relation.relation_type_options");
                return array_get($relation_type_options, $relation_type);
            });

            if(isset($this->custom_table)){
                $grid->model()->where('parent_custom_table_id', $this->custom_table->id);
            }
            
            $grid->tools(function (Grid\Tools $tools) {
                $tools->append(new Tools\GridChangePageMenu('relation', $this->custom_table, false));
            });
            
            $grid->disableExport();
            $grid->actions(function ($actions) {
                $actions->disableView();
            });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return Admin::form(CustomRelation::class, function (Form $form) use ($id) {
            $form->hidden('parent_custom_table_id')->default($this->custom_table->id);
            $form->display('parent_custom_table.table_name', exmtrans("custom_relation.parent_custom_table_name"))->default($this->custom_table->table_name);
            $form->display('parent_custom_table.table_view_name', exmtrans("custom_relation.parent_custom_table_view_name"))->default($this->custom_table->table_view_name);

            $form->select('child_custom_table_id', exmtrans("custom_relation.child_custom_table"))->options(function($child_custom_table_id){
                return CustomTable::all(['id', 'table_view_name'])->pluck('table_view_name', 'id')->toArray();
            })->rules('required');

            $relation_type_options = getTransArray(Define::RELATION_TYPE, "custom_relation.relation_type_options");
            $form->select('relation_type', exmtrans("custom_relation.relation_type"))->options($relation_type_options)->rules('required');

            // $form->saved(function(){
            //     createModel($this->custom_table->table_name);
            // });
            $form->disableReset();
            $form->disableViewCheck();
            $custom_table = $this->custom_table;
            $form->tools(function (Form\Tools $tools) use($id, $form, $custom_table) {
                $tools->disableView();
                $tools->add((new Tools\GridChangePageMenu('relation', $custom_table, false))->render());
            });
        });
    }
}

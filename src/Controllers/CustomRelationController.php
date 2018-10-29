<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;

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
    public function index(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)){
            return;
        }
        return parent::index($request, $content);
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, $id, Content $content)
    {
        $this->setFormViewInfo($request);
        
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)){
            return;
        }
        if (!$this->validateTableAndId(CustomRelation::class, $id, 'relation')) {
            return;
        }
        return parent::edit($request, $id, $content);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)){
            return;
        }
        return parent::create($request, $content);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new CustomRelation);
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
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new CustomRelation);
        $form->hidden('parent_custom_table_id')->default($this->custom_table->id);
        $form->display('parent_custom_table.table_name', exmtrans("custom_relation.parent_custom_table_name"))->default($this->custom_table->table_name);
        $form->display('parent_custom_table.table_view_name', exmtrans("custom_relation.parent_custom_table_view_name"))->default($this->custom_table->table_view_name);

        $custom_table_id = $this->custom_table->id;
        $form->select('child_custom_table_id', exmtrans("custom_relation.child_custom_table"))->options(function($child_custom_table_id) use($custom_table_id){
            //TODO:autority
            return CustomTable
                // ignore self table id
                ::where('id', '<>', $custom_table_id)
                ->get(['id', 'table_view_name'])
                ->pluck('table_view_name', 'id')
                ->toArray();
        })->rules('required');

        $relation_type_options = getTransArray(Define::RELATION_TYPE, "custom_relation.relation_type_options");
        $form->select('relation_type', exmtrans("custom_relation.relation_type"))->options($relation_type_options)->rules('required');
        disableFormFooter($form);
        $custom_table = $this->custom_table;
        $form->tools(function (Form\Tools $tools) use($id, $form, $custom_table) {
            $tools->disableView();
            $tools->add((new Tools\GridChangePageMenu('relation', $custom_table, false))->render());
        });
        return $form;
    }
}

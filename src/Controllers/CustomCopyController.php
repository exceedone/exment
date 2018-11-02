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
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Request as Req;

class CustomCopyController extends AdminControllerTableBase
{
    use ModelForm;

    public function __construct(Request $request){
        parent::__construct($request);
        
        $this->setPageInfo(exmtrans("custom_copy.header"), exmtrans("custom_copy.header"), exmtrans("custom_copy.description"));  
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
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
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUE_CUSTOM_TABLE)){
            return;
        }
        if (!$this->validateTableAndId(CustomCopy::class, $id, 'copy')) {
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
        $grid = new Grid(new CustomCopy);
        $grid->column('from_custom_table.table_view_name', exmtrans("custom_copy.from_custom_table_view_name"))->sortable();
        $grid->column('to_custom_table.table_view_name', exmtrans("custom_copy.to_custom_table_view_name"))->sortable();
        $grid->column('label', exmtrans("plugin.options.label"))->sortable()->display(function($value){
            return array_get($this, 'options.label');
        });
        
        if(isset($this->custom_table)){
            $grid->model()->where('from_custom_table_id', $this->custom_table->id);
        }
        
        $grid->disableCreateButton();
        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(view('exment::custom-value.new-button-copy'));
            $tools->append($this->createNewModal());
            $tools->append(new Tools\GridChangePageMenu('copy', $this->custom_table, false));
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
        $form = new Form(new CustomCopy);
        $form->hidden('from_custom_table_id')->default($this->custom_table->id);
        $form->display('from_custom_table.table_view_name', exmtrans("custom_copy.from_custom_table_view_name"))->default($this->custom_table->table_view_name);

        // get to item 
        // if set $id, get from CustomCopy
        $request = Request::capture();
        // if set posted to_custom_table_id, get from posted data
        if($request->has('to_custom_table_id')){
            $to_table = CustomTable::find($request->get('to_custom_table_id'));
            $form->hidden('to_custom_table_id')->default($request->get('to_custom_table_id'));
        }
        elseif(isset($id)){
            $copy = CustomCopy::find($id);
            $to_table = $copy->to_custom_table;
        }
        // if not set, get query
        else{
            $to_custom_table_suuid = $request->get('to_custom_table');
            $to_table = CustomTable::findBySuuid($to_custom_table_suuid);
        }

        if (isset($to_table)) {
            $form->display('to_custom_table.table_view_name', exmtrans("custom_copy.to_custom_table_view_name"))->default($to_table->table_view_name);
            $form->hidden('to_custom_table_id')->default($to_table->id);
        }

        // exmtrans "plugin". it's same value
        $form->embeds('options', exmtrans("plugin.options.header"), function ($form) {
            $form->text('label', exmtrans("plugin.options.label"))->default(exmtrans("common.copy"));
            $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"))->default('fa-copy');
            $form->text('button_class', exmtrans("plugin.options.button_class"))->help(exmtrans("plugin.help.button_class"));
        })->disableHeader();

        ///// get from and to columns
        $custom_table = $this->custom_table;
        $from_custom_column_options = $this->custom_columns->pluck('column_view_name', 'id');
        $to_custom_column_options = $to_table->custom_columns->pluck('column_view_name', 'id') ?? [];
        $form->hasManyTable('custom_copy_columns', exmtrans("custom_copy.custom_copy_columns"), function($form) use($from_custom_column_options, $to_custom_column_options){
            $form->select('from_custom_column_id', exmtrans("custom_copy.from_custom_column"))->options($from_custom_column_options);
            $form->description('▶');
            $form->select('to_custom_column_id', exmtrans("custom_copy.to_custom_column"))->options($to_custom_column_options);
            $form->hidden('custom_copy_column_type')->default('default');
        })->setTableWidth(10,1)
        ->description(exmtrans("custom_copy.column_description"));

        ///// get input columns
        $form->hasManyTable('custom_copy_input_columns', exmtrans("custom_copy.custom_copy_input_columns"), function($form) use($from_custom_column_options, $to_custom_column_options){
            $form->select('to_custom_column_id', exmtrans("custom_copy.input_custom_column"))->options($to_custom_column_options);
            $form->hidden('custom_copy_column_type')->default('input');
        })->setTableWidth(10,1)
        ->description(exmtrans("custom_copy.input_column_description"));

        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) use($id, $form, $custom_table) {
            $tools->disableView();
            $tools->add((new Tools\GridChangePageMenu('copy', $custom_table, false))->render());
        });
        return $form;
    }

    /**
     * Create new button for modal. 
     */
    protected function createNewModal(){
        $table_name = $this->custom_table->table_name;
        $path = admin_base_path(url_join('copy', $table_name, 'create'));
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->action($path);
        $form->method('GET');
        $form->modalHeader(trans('admin.setting'));

        $form->select('to_custom_table', 'コピー先のテーブル')
            ->options(function($option){
                return CustomTable::where('showlist_flg', true)->pluck('table_view_name', 'suuid');
            })
            ->setWidth(8,3)
            ->help('コピー先のテーブルを選択してください。');        
        // add button
        return $form->render()->render();
    }
}

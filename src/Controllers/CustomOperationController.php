<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Field\ChangeField;

class CustomOperationController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);
        
        $this->setPageInfo(exmtrans("custom_operation.header"), exmtrans("custom_operation.header"), exmtrans("custom_operation.description"), 'fa-th-list');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        return parent::index($request, $content);
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }
        if (!$this->validateTableAndId(CustomOperation::class, $id, 'view')) {
            return;
        }

        // check has system permission
        if (!$this->hasSystemPermission()) {
            $operation = CustomOperation::getEloquent($id);

            if ($operation->created_user_id != \Exment::user()->base_user_id) {
                Checker::error();
                return false;
            }
        }
        
        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return;
        }

        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['operation_name']));
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
        $grid = new Grid(new CustomOperation);
        $grid->column('custom_table.table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->column('operation_name', exmtrans("custom_operation.operation_name"))->sortable();
        
        if (isset($this->custom_table)) {
            $grid->model()->where('custom_table_id', $this->custom_table->id);
            $custom_table = $this->custom_table;
        }

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) use ($custom_table) {
            if (isset($custom_table)) {
                $table_name = $custom_table->table_name;
            }
            if (boolval($actions->row->disabled_delete)) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\GridChangePageMenu('operation', $this->custom_table, false));
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null, $copy_id = null)
    {
        // get request
        $request = Request::capture();
        $copy_custom_operation = CustomOperation::getEloquent($copy_id);
        
        $form = new Form(new CustomOperation);

        if (!isset($id)) {
            $id = $form->model()->id;
        }
        if (isset($id)) {
            $model = CustomOperation::getEloquent($id);
        }
        if (isset($model)) {
            $suuid = $model->suuid;
        } else {
            $suuid = null;
        }
        
        $form->hidden('custom_table_id')->default($this->custom_table->id);
        
        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);

        $form->text('operation_name', exmtrans("custom_operation.operation_name"))->required()->rules("max:40");
        
        $custom_table = $this->custom_table;
        $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));

        // filter setting
        $form->hasManyTable('custom_operation_columns', exmtrans("custom_operation.custom_operation_columns"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_operation.view_column_target"))->required()
                ->options($this->custom_table->getColumnsSelectOptions([
                    'append_table' => true,
                    'index_enabled_only' => false,
                    'include_parent' => false,
                    'include_child' => false,
                    'include_system' => false,
                ]));

            $label = exmtrans("custom_operation.update_value_text");
            $form->changeField('update_value', $label)
                ->required()
                ->rules("changeFieldValue:$label");
        })->setTableColumnWidth(4, 4, 3, 1)
        ->description(sprintf(exmtrans("custom_operation.description_custom_operation_columns"), $manualUrl));

        $custom_table = $this->custom_table;

        $form->tools(function (Form\Tools $tools) use ($id, $suuid, $form, $custom_table) {
            $tools->add((new Tools\GridChangePageMenu('operation', $custom_table, false))->render());
        });
        
        $table_name = $this->custom_table->table_name;
        $script = <<<EOT
            $('#has-many-table-custom_operation_columns').off('change').on('change', '.view_column_target', function (ev) {
                $.ajax({
                    url: admin_url("operation/$table_name/filter-value"),
                    type: "GET",
                    data: {
                        'target_name': $(this).attr('name'),
                        'target_val': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr.has-many-table-custom_operation_columns-row').find('td:nth-child(2)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
EOT;
        Admin::script($script);
        return $form;
    }

    protected function hasSystemPermission()
    {
        return $this->custom_table->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VIEW]);
    }

    /**
     * get filter condition
     */
    public function getFilterValue(Request $request)
    {
        if ($request->has('target_name') && $request->has('target_val')) {
            $target_name = $request->get('target_name');
            $target_val = $request->get('target_val');
        } else {
            return [];
        }

        $columnname = 'update_value';
        $label = exmtrans('custom_operation.'.$columnname.'_text');

        $field = new ChangeField($columnname, $label);
        $field->required()
            ->rules("changeFieldValue:$label")
            ->data([
                'view_column_target' => $target_val,
        ]);
        $element_name = str_replace('view_column_target', 'update_value', $target_name);
        $field->setElementName($element_name);

        $view = $field->render();
        return \json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }
}

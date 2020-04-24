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
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Field\ChangeField;

class CustomOperationController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
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
        
        // filter setting
        $hasManyTable = new Tools\ConditionHasManyTable($form, [
            'name' => 'custom_operation_columns',
            'showConditionKey' => false,
            'ajax' => admin_urls('webapi', $custom_table->table_name, 'filter-value'),
            //'linkage' => json_encode(['condition_key' => admin_urls('webapi', $custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $this->custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_child' => false,
                'include_system' => false,
                'ignore_attachment' => true,
            ]),
            'custom_table' => $custom_table,
            'filterKind' => FilterKind::OPERATION,
            'condition_target_name' => 'view_column_target',
            'condition_key_name' => 'view_column_target',
            'condition_value_name' => 'update_value',
        ]);

        $hasManyTable->callbackField(function ($field) {
            $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
            $field->description(sprintf(exmtrans("custom_operation.description_custom_operation_columns"), $manualUrl));
        });

        $hasManyTable->render();
        
        $custom_table = $this->custom_table;

        $form->tools(function (Form\Tools $tools) use ($id, $suuid, $form, $custom_table) {
            $tools->add(new Tools\GridChangePageMenu('operation', $custom_table, false));
        });
        
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

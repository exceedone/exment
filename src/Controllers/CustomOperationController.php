<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Encore\Admin\Auth\Permission as Checker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\ConditionItems\ConditionItemBase;

class CustomOperationController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_operation.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_operation.description"), 'fa-th-list');
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
     * Edit
     *
     * @param Request $request
     * @param Content $content
     * @param $tableKey
     * @param $id
     * @return Content|false|void
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

            if ($operation->created_user_id != \Exment::getUserId()) {
                Checker::error();
                return false;
            }
        }

        return parent::edit($request, $content, $tableKey, $id);
    }

    /**
     * Create interface.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|void
     */
    public function create(Request $request, Content $content)
    {
        //Validation table value
        if (!$this->validateTable($this->custom_table, Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
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
        $grid = new Grid(new CustomOperation());
        $grid->column('operation_name', exmtrans("custom_operation.operation_name"))->sortable();
        $grid->column('operation_type', exmtrans("custom_operation.operation_type"))->sortable()->display(function ($val) {
            return collect(toArray($val))->map(function ($v) {
                return array_get(CustomOperationType::transArray("custom_operation.operation_type_options_short"), $v);
            })->implode(exmtrans('common.separate_word'));
        });
        $grid->column('active_flg', exmtrans("custom_operation.active_flg"))->display(function ($active_flg) {
            return \Exment::getTrueMark($active_flg);
        })->escape(false);

        $grid->model()->where('custom_table_id', $this->custom_table->id);

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            if (boolval($actions->row->disabled_delete)) {
                $actions->disableDelete();
            }
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new Tools\CustomTableMenuButton('operation', $this->custom_table));
        });

        // filter
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();

            $filter->like('operation_name', exmtrans("custom_operation.operation_name"));
        });

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param int|null $id
     * @return Form
     */
    protected function form($id = null)
    {
        // get request
        $request = Request::capture();

        $form = new Form(new CustomOperation());

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

        $form->internal('custom_table_id')->default($this->custom_table->id);

        $form->display('custom_table.table_name', exmtrans("custom_table.table_name"))->default($this->custom_table->table_name);
        $form->display('custom_table.table_view_name', exmtrans("custom_table.table_view_name"))->default($this->custom_table->table_view_name);

        $form->text('operation_name', exmtrans("custom_operation.operation_name"))->required()->rules("max:40");

        $form->multipleSelect('operation_type', exmtrans("custom_operation.operation_type"))
            ->help(exmtrans("custom_operation.help.operation_type"))
            ->options(function () {
                return CustomOperationType::transArray("custom_operation.operation_type_options");
            })->required()
            ->attribute(['data-filtertrigger' =>true]);

        $form->embeds('options', null, function ($form) {
            $form->text('button_label', exmtrans("custom_operation.options.button_label"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'operation_type', 'value' => [CustomOperationType::BUTTON, CustomOperationType::BULK_UPDATE]])]);
            $form->icon('button_icon', exmtrans("custom_operation.options.button_icon"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'operation_type', 'value' => CustomOperationType::BUTTON])])
                ->help(exmtrans("custom_operation.help.button_icon"));
            $form->text('button_class', exmtrans("custom_operation.options.button_class"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'operation_type', 'value' => CustomOperationType::BUTTON])])
                ->help(exmtrans("custom_operation.help.button_class"));
        })->disableHeader();

        $form->switchbool('active_flg', exmtrans("custom_operation.active_flg"))
            ->default(true);

        $custom_table = $this->custom_table;

        // update column setting
        $hasManyTable = new Tools\ConditionHasManyTable($form, [
            'name' => 'custom_operation_columns',
            'showConditionKey' => false,
            'linkage' => json_encode(['operation_update_type' => admin_urls('webapi', $custom_table->table_name, 'operation-update-type')]),
            'ajax' => admin_urls('webapi', $custom_table->table_name, 'operation-filter-value'),
            'targetOptions' => $this->custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_child' => false,
                'include_system' => false,
                'ignore_attachment' => true,
                'ignore_autonumber' => true,
            ]),
            'custom_table' => $custom_table,
            'filterKind' => FilterKind::OPERATION,
            'condition_target_name' => 'view_column_target',
            'condition_key_name' => 'operation_update_type',
            'condition_value_name' => 'update_value_text',
            'label' => exmtrans('custom_operation.custom_operation_columns'),
            'condition_target_label' => exmtrans('custom_operation.view_column_target'),
            'condition_value_label' => exmtrans('custom_operation.update_value_text'),
            'conditionCallback' => function ($form) use ($custom_table) {
                $form->select('operation_update_type', exmtrans('custom_operation.operation_update_type'))->required()
                    ->options(function ($val, $select, $model) use ($custom_table) {
                        $data = $select->data();
                        $item = ConditionItemBase::getItemByRequest($custom_table, array_get($data, 'view_column_target'));
                        if (is_null($item)) {
                            return null;
                        }

                        return collect($item->getOperationUpdateType())->mapWithKeys(function ($item) {
                            return [$item['id'] => $item['text']];
                        });
                    })
                    ->removeRules(\Encore\Admin\Validator\HasOptionRule::class);
            },
            'valueCallback' => function ($data, $field) use ($custom_table) {
                $item = ConditionItemBase::getItemByRequest($custom_table, array_get($data, 'view_column_target'));
                if (is_null($item)) {
                    return null;
                }

                return $item->getOperationFilterValueChangeField(array_get($data, 'operation_update_type'), $field->getElementName());
            },
        ]);

        $hasManyTable->callbackField(function ($field) {
            $manualUrl = getManualUrl('column?id='.exmtrans('custom_column.options.index_enabled'));
            $field->descriptionHtml(sprintf(exmtrans("custom_operation.help.custom_operation_columns"), $manualUrl));
            $field->setTableColumnWidth(4, 3, 4, 1);
        });
        $hasManyTable->render();

        ///// get input columns
        $form->hasManyTable('custom_operation_input_columns', exmtrans("custom_operation.custom_operation_input_columns"), function ($form) use ($custom_table) {
            $form->select('view_column_target', exmtrans("custom_operation.input_custom_column"))
                ->options($custom_table->getColumnsSelectOptions([
                    'append_table' => true,
                    'include_system' => false,
                    'ignore_attachment' => true,
                    'ignore_autonumber' => true,
                ]))->required();
            $form->hidden('operation_column_type')->default(CopyColumnType::INPUT);
        })->setTableWidth(10, 1)
        ->descriptionHtml(exmtrans("custom_operation.input_column_description"));

        // filter setting
        $filterTable = new Tools\ConditionHasManyTable($form, [
            'ajax' => admin_urls('webapi', $custom_table->table_name, 'filter-value'),
            'name' => 'custom_operation_conditions',
            'linkage' => json_encode(['condition_key' => url_join($custom_table->table_name, 'filter-condition')]),
            'targetOptions' => $custom_table->getColumnsSelectOptions([
                'include_system' => false,
                'include_condition' => true,
                'ignore_attachment' => true,
            ]),
            'custom_table' => $custom_table,
            'filterKind' => FilterKind::OPERATION,
            'label' => exmtrans('custom_operation.custom_operation_conditions'),
        ]);

        $filterTable->callbackField(function ($field) {
            $field->descriptionHtml(sprintf(exmtrans("custom_operation.help.custom_operation_conditions")));
        });
        $filterTable->render();

        $form->radio('condition_join', exmtrans("condition.condition_join"))
            ->options(exmtrans("condition.condition_join_options"))
            ->default('and');

        $form->checkboxone('condition_reverse', exmtrans("condition.condition_reverse"))
            ->option(exmtrans("condition.condition_reverse_options"));

        // check inputs and operation_type before save
        $form->saving(function (Form $form) {
            if (!is_null($form->custom_operation_input_columns)) {
                if (collect($form->custom_operation_input_columns)->contains(function ($val) {
                    return array_get($val, Form::REMOVE_FLAG_NAME) != 1;
                })) {
                    if (array_intersect($form->operation_type, [CustomOperationType::CREATE, CustomOperationType::UPDATE])) {
                        admin_toastr(exmtrans('custom_operation.message.invalid_operation_type'), 'error');
                        return back()->withInput();
                    }
                }
            }
        });

        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->add(new Tools\CustomTableMenuButton('operation', $custom_table));
        });
        $form->disableEditingCheck(false);

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

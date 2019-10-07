<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Form\Field\WorkFlow as WorkFlowField;
use Exceedone\Exment\Form\Field\ChangeField;
use Exceedone\Exment\Services\AuthUserOrgHelper;

class WorkflowController extends AdminControllerBase
{
    use HasResourceActions;

    protected $exists = false;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("workflow.header"), exmtrans("workflow.header"), exmtrans("workflow.description"), 'fa-share-alt');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Workflow);
        $grid->column('id', exmtrans("common.id"));
        $grid->column('workflow_type', exmtrans("workflow.workflow_type"))->display(function($v){
            return WorkflowType::getEnum($v)->transKey('workflow.workflow_type_options');
        });
        $grid->column('workflow_tables', exmtrans("custom_table.table"))->display(function($v){
            if(is_null($custom_table = $this->getDesignatedTable())){
                return null;
            }

            return $custom_table->table_view_name;
        });
        $grid->column('workflow_view_name', exmtrans("workflow.workflow_view_name"))->sortable();
        
        $grid->disableExport();
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
            // add new edit link
            $linker = (new Linker)
                ->url(admin_urls('workflow', $actions->getKey(), 'edit?action=1'))
                ->icon('fa-link')
                ->tooltip(exmtrans('workflow.action'));
            $actions->prepend($linker);
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
        // get request
        $request = Request::capture();
        if (!is_null($request->input('action'))) {
            $is_action = $request->input('action');
        } else {
            $is_action = $request->query('action')?? '0';
        }

        if ($is_action) {
            return $this->actionForm($id, $is_action);
        } else {
            return $this->statusForm($id, $is_action);
        }
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    public function action(Request $request, Content $content, $id)
    {
        return $this->AdminContent($content)->body($this->actionForm($id)->edit($id));
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function statusForm($id, $is_action)
    {
        $workflow = Workflow::find($id);

        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($id, $is_action));
        $form->text('workflow_view_name', exmtrans("workflow.workflow_view_name"))
            ->required()
            ->rules("max:40");

        // is create
        if(!isset($workflow)){
            $form->select('workflow_type', exmtrans('workflow.workflow_type'))
                ->options(WorkflowType::transKeyArray('workflow.workflow_type_options'))
                ->attribute(['data-filtertrigger' =>true])
                ->config('allowClear', false)
                ->help(exmtrans('common.help.init_flg') . exmtrans('workflow.help.workflow_type'))
                ->required();
                
            $form->select('custom_table_id', exmtrans('custom_table.table'))->options(function ($value) {
                $options = CustomTable::filterList()->pluck('table_view_name', 'id')->toArray();
                return $options;
            })->required()
            ->attribute(['data-filter' => json_encode(['key' => 'workflow_type', 'value' => [WorkflowType::TABLE]])])
            ;
            $form->ignore('custom_table_id');
        }
        // is update
        else{
            $form->display('workflow_type', exmtrans('workflow.workflow_type'))
                ->displayText(WorkflowType::getEnum($workflow->workflow_type)->transKey('workflow.workflow_type_options'))
                ;

            if($workflow == WorkflowType::TABLE){
                $form->display('custom_table_id', exmtrans('custom_table.table'));
            }
        }
        
        $form->text('start_status_name', exmtrans("workflow.start_status_name"))
            ->required()
            ->rules("max:30");

        $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) {
            $form->text('status_name', exmtrans("workflow.status_name"))->help(exmtrans('workflow.help.status_name'));
            $form->switchbool('datalock_flg', exmtrans("workflow.datalock_flg"))->help(exmtrans('workflow.help.editable_flg'));
            $form->hidden('order')->default(0);
        })->setTableColumnWidth(6, 2, 2)
            ->setTableWidth(8, 2)
            ->rowUpDown('order')
            ->description(sprintf(exmtrans("workflow.description_workflow_statuses")));
        
        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->savedInTransaction(function (Form $form) use ($id) {
            $model = $form->model();

            // save table info
            if(is_null($custom_table_id = request()->get('custom_table_id'))){
                return;
            }

            WorkflowTable::create([
                'custom_table_id' => $custom_table_id,
                'workflow_id' => $model->id,
            ]);
        });

        $form->saved(function (Form $form) use ($id) {
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'edit?action=1');
    
                admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
                return redirect($workflow_action_url);
            }
        });

        return $form;
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function actionForm($id, $is_action)
    {
        $workflow = Workflow::find($id);
        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($id, $is_action));
        $form->hidden('action')->default(1);
        $form->display('workflow_view_name', exmtrans("workflow.workflow_view_name"));

        $form->hasManyTable('workflow_actions', exmtrans("workflow.workflow_actions"), function($form) use($id, $workflow){
            $form->workflowStatusSelects('status_from', exmtrans("workflow.status_name"))
                ->config('allowClear', false)
                ->options(function($value, $field){
                    return $this->getStatusOptions($field->getIndex() === 0);
                });

            $form->valueModal('work_conditions', exmtrans("workflow.work_conditions"))
                ->ajax(admin_urls('workflow', $id, 'modal', 'condition'))
                ->modalContentname('workflow_actions_work_conditions')
                ->setElementClass('workflow_actions_work_conditions')
                ->buttonClass('btn-sm btn-default')
                ->valueTextScript('Exment.WorkflowEvent.GetConditionSettingValText();')
                ->hiddenFormat(function($value){
                    return collect($value)->toJson();
                })
                ->text(function ($value, $field) use($workflow) {
                    if(is_nullorempty($value)){
                        return null;
                    }

                    // set text
                    $texts = [];
                    foreach(range(0, 2) as $index){
                        if(!array_has($value, "work_condition_enabled_$index") || !boolval(array_get($value, "work_condition_enabled_$index"))){
                            break;
                        }
                        $texts[] = WorkflowStatus::getWorkflowStatusName(array_get($value, "work_condition_status_to_$index"), $workflow);
                    }
                    return $texts;
                })
                ->nullText(exmtrans("common.no_setting"))
            ;

            
            $form->valueModal('work_targets', exmtrans("workflow.work_targets"))
                ->ajax(admin_urls('workflow', $id, 'modal', 'target'))
                ->modalContentname('workflow_actions_work_targets')
                ->setElementClass('workflow_actions_work_targets')
                ->buttonClass('btn-sm btn-default')
                ->valueTextScript('Exment.WorkflowEvent.GetSettingValText();')
                ->hiddenFormat(function($value){
                    if(is_nullorempty($value)){
                        return null;
                    }

                    $result = [];
                    collect($value)->each(function($v) use(&$result){
                        $result['modal_' . array_get($v, 'related_type')][] = array_get($v, 'related_id');
                    });
                    return collect($result)->toJson();
                })
                ->text(function ($value, $data) {
                    if(is_nullorempty($value)){
                        return;
                    }

                    // set text
                    $texts = [];
                    foreach($value as $v){
                        $texts[] = array_get($v, 'user_organization.label');
                    }
                    return $texts;
                })
                ->nullText(exmtrans("common.all_user"))
            ;

            $form->workflowOptions('options', exmtrans("workflow.option"));
        })->setTableColumnWidth(3, 2, 3, 3, 1);

        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });

        $form->ignore(['action']);

        $form->saving(function (Form $form) {
            if (!is_null($form->workflow_actions)) {
                $actions = collect($form->workflow_actions)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                });
                foreach($actions as $action) {
                    if (array_get($action, 'status_from') == array_get($action, 'status_to')) {
                        admin_toastr(exmtrans('workflow.message.status_nochange'), 'error');
                        return back()->withInput();
                    }
                }
            }
        });

        return $form;
    }

    /**
     * validate before delete.
     */
    protected function validateDestroy($id)
    {
        // check referenced from customtable
        $refer_count = CustomTable::where('workflow_id', $id)
            ->count();

        if ($refer_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('workflow.message.reference_error'),
            ];
        }
    }

    protected function getProgressInfo($id, $is_action) {
        $steps = [];
        $hasAction = false;
        $hasStatus = false;
        $workflow_action_url = null;
        $workflow_status_url = null;
        if (isset($id)) {
            $hasAction = WorkflowAction::where('workflow_id', $id)->count() > 0;
            $hasStatus = WorkflowStatus::where('workflow_id', $id)->count() > 0;
            $workflow_action_url = admin_urls('workflow', $id, 'edit?action=1');
            $workflow_status_url = admin_urls('workflow', $id, 'edit');
        }
        $steps[] = [
            'active' => !$is_action,
            'complete' => $hasStatus,
            'url' => $is_action? $workflow_status_url: null,
            'description' => exmtrans('workflow.workflow_statuses')
        ];
        $steps[] = [
            'active' => $is_action,
            'complete' => $hasAction,
            'url' => !$is_action? $workflow_action_url: null,
            'description' => exmtrans('workflow.workflow_actions')
        ];
        return $steps;
    }

    /**
     * Get target modal html
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function targetModal(Request $request, $id){
        $workflow = Workflow::find($id);
        $custom_table = $workflow->getDesignatedTable();

        // get selected value
        $value = $request->get('workflow_actions_work_targets');
        $value = jsonToArray($value);

        $form = AuthUserOrgHelper::getUserOrgModalForm($custom_table, $value, [
            'prependCallback' => function($form) use($value){
                $form->radio('work_target_type', exmtrans('workflow.work_target_type'))
                    ->help(exmtrans('workflow.help.work_target_type'))
                    ->attribute(['data-filtertrigger' =>true])
                    ->default(array_get($value, 'work_target_type', 'all'))
                    ->options(['all' => exmtrans('common.all_user'), 'select' => trans('admin.choose')]);
            }
        ]);

        // set custom column
        if(isset($custom_table)){
            $options = $custom_table->custom_columns
                ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
                ->pluck('column_view_name', 'id');
            $form->multipleSelect('modal_column', exmtrans('common.custom_column'))
                ->options($options)
                ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'select'])])
                ->default(array_get($value, 'column'));
        }

        // set workflow system column
        $form->multipleSelect('modal_system', exmtrans('common.system'))
            ->options(WorkflowTargetSystem::transArray('common'))
            ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'select'])])
            ->default(array_get($value, SystemTableName::SYSTEM));

        $form->hidden('valueModalUuid')->default($request->get('uuid'));

        $form->setWidth(9, 2);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('workflow.work_targets'),
            'showReset' => true,
            'submitlabel' => trans('admin.setting'),
            'contentname' => 'workflow_actions_work_targets',
        ]);
    }

    /**
     * Get condition modal html
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function conditionModal(Request $request, $id){
        $workflow = Workflow::find($id);
        $custom_table = $workflow->getDesignatedTable();
        $statusOptions = $workflow->getStatusOptions();

        // get selected value
        $value = $request->get('workflow_actions_work_conditions');
        $value = jsonToArray($value);

        $form = new ModalForm($value);

        $form->description('このアクションを実行するための条件と、実行後のステータスを設定します。条件は3つまで設定できます。<br />※常に固定のアクションを実行する場合、「条件1」の「実行後ステータス」の設定のみ行ってください。')
            ->setWidth(10, 2);

        foreach(range(0, 2) as $index){
            $form->exmheader('条件' . ($index + 1))
                ->hr();

            if($index === 0){
                $form->hidden("work_condition_enabled_{$index}")
                ->default(1);
            }else{
                $form->checkboxone("work_condition_enabled_{$index}", 'work_condition_enabled')
                ->setLabelClass(['invisible'])
                ->setWidth(10, 2)
                ->default(array_get($value, "work_condition_enabled_{$index}", 0))
                ->attribute(['data-filtertrigger' =>true])
                ->option(['1' => exmtrans('custom_form.available')]);
            }
            
            $form->select("work_condition_status_to_{$index}", exmtrans('workflow.status_to'))
                ->options($statusOptions)
                ->required()
                //->default(array_get($value, "work_condition_status_to_{$index}"))
                ->setElementClass('work_condition_status_to')
                ->attribute(['data-filter' => json_encode(['key' => "work_condition_enabled_{$index}", 'value' => '1'])])
                ->setWidth(4, 2);

            $default = array_get($value, "work_condition_filter_{$index}", []);
            $form->hasManyTable("work_condition_filter_{$index}", exmtrans("custom_view.custom_view_filters"), function ($form) use ($custom_table, $id) {
                $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                    ->options($custom_table->getColumnsSelectOptions(
                        [
                        ]
                    ))
                    ->attribute([
                        'data-linkage' => json_encode(['view_filter_condition' => admin_urls('view', $custom_table->table_name, 'filter-condition')]),
                        'data-change_field_target' => 'view_column_target',
                    ]);
    
                $form->select('view_filter_condition', exmtrans("custom_view.view_filter_condition"))->required()
                    ->options(function ($val, $select) {
                        // if null, return empty array.
                        if (!isset($val)) {
                            return [];
                        }
    
                        $data = $select->data();
                        $view_column_target = array_get($data, 'view_column_target');
    
                        // get column item
                        $column_item = CustomViewFilter::getColumnItem($view_column_target);
    
                        ///// get column_type
                        $column_type = $column_item->getViewFilterType();
    
                        // if null, return []
                        if (!isset($column_type)) {
                            return [];
                        }
    
                        // get target array
                        $options = array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), $column_type);
                        return collect($options)->mapWithKeys(function ($array) {
                            return [$array['id'] => exmtrans('custom_view.filter_condition_options.'.$array['name'])];
                        });
    
                        return [];
                    });
                $label = exmtrans('custom_view.view_filter_condition_value_text');
                $form->changeField('view_filter_condition_value', $label)
                    ->ajax(admin_url("workflow/{$id}/filter-value"))
                    ->setEventTrigger('.view_filter_condition')
                    ->setEventTarget('select.view_column_target')
                    ->rules("changeFieldValue:$label");
            })->setTableColumnWidth(4, 4, 3, 1)
            ->setTableWidth(10, 2)
            ->setRelatedValue($default)
            ->attribute(['data-filter' => json_encode(['key' => "work_condition_enabled_{$index}", 'value' => '1'])])
            ->disableHeader();
        }


        $form->hidden('valueModalUuid')->default($request->get('uuid'));

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans("workflow.work_conditions"),
            'showReset' => true,
            'submitlabel' => trans('admin.setting'),
            'contentname' => 'workflow_actions_work_conditions',
        ]);
    }

    /**
     * TODO:copy and paste. DO refactor.
     *
     * @param Request $request
     * @return void
     */
    public function getFilterValue(Request $request){
        $data = $request->all();

        if (!array_key_exists('target', $data) ||
            !array_key_exists('cond_val', $data) ||
            !array_key_exists('cond_name', $data)) {
            return [];
        }
        $columnname = 'view_filter_condition_value';
        $label = exmtrans('custom_view.'.$columnname.'_text');

        $field = new ChangeField($columnname, $label);
        $field->data([
            'view_column_target' => $data['target'],
            'view_filter_condition' => $data['cond_val']
        ])->rules("changeFieldValue:$label");
        $element_name = str_replace('view_filter_condition', 'view_filter_condition_value', $data['cond_name']);
        $field->setElementName($element_name);

        $view = $field->render();
        return \json_encode(['html' => $view->render(), 'script' => $field->getScript()]);
    }
}

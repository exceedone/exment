<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Layout\Content;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowTargetSystem;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Form\Tools\ConditionHasManyTable;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Symfony\Component\HttpFoundation\Response;
use \Carbon\Carbon;

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
        $grid->column('workflow_type', exmtrans("workflow.workflow_type"))->displayEscape(function ($v) {
            return WorkflowType::getEnum($v)->transKey('workflow.workflow_type_options');
        });
        $grid->column('workflow_tables', exmtrans("custom_table.table"))->displayEscape(function ($v) {
            if (is_null($custom_table = $this->getDesignatedTable())) {
                return null;
            }

            return $custom_table->table_view_name;
        });
        $grid->column('workflow_view_name', exmtrans("workflow.workflow_view_name"))->sortable();
        $grid->column('workflow_statuses', exmtrans("workflow.status_name"))->displayEscape(function ($value) {
            return $this->getStatusesString();
        });
        $grid->column('setting_completed_flg', exmtrans("workflow.setting_completed_flg"))->display(function ($value) {
            if (boolval($value)) {
                return getTrueMark($value);
            }

            return null;
        });
        
        $grid->disableExport();
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();

            // add new edit link
            $linker = (new Linker)
                ->url(admin_urls('workflow', $actions->getKey(), 'edit?action=2'))
                ->icon('fa-exchange')
                ->tooltip(exmtrans('workflow.action'));
            $actions->prepend($linker);
            
            if ($actions->row->disabled_delete) {
                $actions->disableDelete();
            }
        });

        $grid->tools(function ($tools) {
            if (Workflow::hasSettingCompleted()) {
                $tools->append(view('exment::tools.button', [
                    'href' => admin_url('workflow/beginning'),
                    'label' => exmtrans('workflow.beginning'),
                    'icon' => 'fa-cog',
                    'btn_class' => 'btn-primary',
                ]));
            }
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
        if (!isset($id)) {
            return $this->statusForm();
        }

        // get request
        $action = request()->get('action', 1);

        switch ($action) {
            case 2:
                return $this->actionForm($id);
            case 3:
                return $this->beginningForm($id);
            default:
                return $this->statusForm($id);
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
    protected function statusForm($id = null)
    {
        $workflow = Workflow::getEloquent($id);

        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($workflow, 1));

        $form->description(exmtrans('common.help.more_help'));

        $form->text('workflow_view_name', exmtrans("workflow.workflow_view_name"))
            ->required()
            ->rules("max:40");

        // is create
        if (!isset($workflow)) {
            $form->select('workflow_type', exmtrans('workflow.workflow_type'))
                ->options(WorkflowType::transKeyArray('workflow.workflow_type_options'))
                ->attribute(['data-filtertrigger' =>true])
                ->config('allowClear', false)
                ->help(exmtrans('common.help.init_flg') . exmtrans('workflow.help.workflow_type'))
                ->required();
                
            $form->select('custom_table_id', exmtrans('custom_table.table'))->options(function ($value) {
                return CustomTable::allRecords(function ($custom_table) {
                    return !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())
                && !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY());
                })->pluck('table_view_name', 'id')->toArray();
            })->required()
            ->attribute(['data-filter' => json_encode(['key' => 'workflow_type', 'value' => [WorkflowType::TABLE]])])
            ;
            $form->ignore('custom_table_id');
        }
        // is update
        else {
            $form->display('workflow_type', exmtrans('workflow.workflow_type'))
                ->displayText(WorkflowType::getEnum($workflow->workflow_type)->transKey('workflow.workflow_type_options'))
                ;

            if ($workflow->workflow_type == WorkflowType::TABLE) {
                $custom_table = $workflow->getDesignatedTable();
                $form->display('custom_table_id', exmtrans('custom_table.table'))
                    ->default($custom_table->table_view_name ?? null);
            }
        }
        
        $form->text('start_status_name', exmtrans("workflow.start_status_name"))
            ->required()
            ->help(exmtrans("workflow.help.start_status_name"))
            ->rules("max:30");

        $field = $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) {
            $form->text('status_name', exmtrans("workflow.status_name"))->help(exmtrans('workflow.help.status_name'));
            $form->switchbool('datalock_flg', exmtrans("workflow.datalock_flg"))->help(exmtrans('workflow.help.datalock_flg'));
            $form->hidden('order')->default(0);
        })->setTableWidth(8, 2)
        ->required()
        ->setTableColumnWidth(6, 2, 2);
        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $field->disableOptions();
        } else {
            $field->rowUpDown('order')
                ->description(sprintf(exmtrans("workflow.description_workflow_statuses")));
        }
        
        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->savedInTransaction(function (Form $form) use ($id) {
            $model = $form->model();

            // get workflow_statuses and set completed fig
            $statuses = $model->workflow_statuses()->orderby('order', 'desc')->get();

            foreach ($statuses as $index => $status) {
                $status->completed_flg = ($index === 0);
                $status->save();
            }

            // save table info
            if (request()->get('workflow_type') != WorkflowType::TABLE) {
                return;
            }
            if (is_null($custom_table_id = request()->get('custom_table_id'))) {
                return;
            }

            WorkflowTable::create([
                'custom_table_id' => $custom_table_id,
                'workflow_id' => $model->id,
            ]);
        });

        $self = $this;
        $form->tools(function (Form\Tools $tools) use ($self, $workflow) {
            $self->appendActivateButton($workflow, $tools);
            $self->appendTableSettingButton($workflow, $tools);
            $self->disableDelete($workflow, $tools);
        });

        $form->saved(function (Form $form) use ($id) {
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'edit?action=2');
    
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
    protected function actionForm($id)
    {
        $workflow = Workflow::getEloquent($id);

        $form = new Form(new Workflow);
        $form->progressTracker()->options($this->getProgressInfo($workflow, 2));

        $form->description(exmtrans('common.help.more_help'));

        $form->hidden('action')->default(2);
        $form->display('workflow_view_name', exmtrans("workflow.workflow_view_name"));
        $form->display('workflow_status', exmtrans("workflow.status_name"))
            ->default($workflow->getStatusesString());

        if ($workflow->workflow_type == WorkflowType::TABLE) {
            $custom_table = $workflow->getDesignatedTable();
            $form->display('custom_table_id', exmtrans('custom_table.table'))
                ->default($custom_table->table_view_name ?? null);
        }
        
        $field = $form->hasManyTable('workflow_actions', exmtrans("workflow.workflow_actions"), function ($form) use ($id, $workflow) {
            $form->workflowStatusSelects('status_from', exmtrans("workflow.status_name"))
                ->config('allowClear', false)
                ->options(function ($value, $field) {
                    return $this->getStatusOptions($field->getIndex() === 0);
                });

            $form->valueModal('work_conditions', exmtrans("workflow.work_conditions"))
                ->ajax(admin_urls('workflow', $id, 'modal', 'condition'))
                ->modalContentname('workflow_actions_work_conditions')
                ->setElementClass('workflow_actions_work_conditions')
                ->buttonClass('btn-sm btn-default')
                ->help(exmtrans("workflow.help.work_conditions"))
                ->required()
                ->valueTextScript('Exment.WorkflowEvent.GetConditionSettingValText();')
                ->hiddenFormat(function ($value) {
                    if (is_nullorempty($value)) {
                        return null;
                    }

                    $value = Condition::getWorkConditions($value);

                    return collect($value)->toJson();
                })
                ->text(function ($value, $field) use ($workflow) {
                    if (is_nullorempty($value)) {
                        return null;
                    }

                    $work_conditions = Condition::getWorkConditions($value);

                    // set text
                    $texts = [];
                    foreach ($work_conditions as $work_condition) {
                        if (!boolval(array_get($work_condition, 'enabled_flg'))) {
                            continue;
                        }
                        $text = WorkflowStatus::getWorkflowStatusName(array_get($work_condition, "status_to"), $workflow);

                        if (!is_nullorempty(array_get($work_condition, 'workflow_conditions'))) {
                            $text .= exmtrans('workflow.has_condition');
                        }

                        $texts[] = $text;
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
                ->help(exmtrans("workflow.help.work_targets"))
                ->required()
                ->hiddenFormat(function ($value, $field) {
                    if (is_nullorempty($value)) {
                        return WorkflowWorkTargetType::getTargetTypeDefault($field->getIndex());
                    }

                    return collect(jsonToArray($value))->toJson();
                })
                ->text(function ($value, $field) {
                    if (is_nullorempty($value)) {
                        return WorkflowWorkTargetType::getTargetTypeNameDefault($field->getIndex());
                    }

                    $value = jsonToArray($value);

                    if (array_get($value, 'work_target_type') == WorkflowWorkTargetType::ACTION_SELECT) {
                        return WorkflowWorkTargetType::ACTION_SELECT()->transKey('workflow.work_target_type_options');
                    }

                    $texts = collect(WorkflowAuthority::getAuhoritiesFromValue($value))
                        ->filter()->map(function ($authority) {
                            return $authority->authority_text;
                        });

                    return $texts;

                    // $action = WorkflowAction::getEloquentDefault($field->data()['id']);
                    // if (!isset($action)) {
                    //     return WorkflowWorkTargetType::getTargetTypeNameDefault($field->getIndex());
                    // }
                    
                    // return $action->getAuthorityTargets(null, false, true);
                })
                ->nullText(exmtrans("common.no_setting"))
                ->nullValue(function ($value, $field) {
                    return WorkflowWorkTargetType::getTargetTypeDefault($field->getIndex());
                })
            ;

            $form->workflowOptions('options', exmtrans("workflow.option"));
        })->setTableColumnWidth(3, 2, 3, 3, 1)
           ->setRelatedValue([[]])
           ->required()
           ->hideDeleteButtonRow(1);

        $self = $this;
        $form->tools(function (Form\Tools $tools) use ($self, $workflow) {
            $self->appendActivateButton($workflow, $tools);
            $self->appendTableSettingButton($workflow, $tools);
            $self->disableDelete($workflow, $tools);
        });

        $form->ignore(['action']);

        $form->saving(function (Form $form) {
            $result = $this->validateData($form);
            if ($result instanceof Response) {
                return $result;
            }
        });

        return $form;
    }

    /**
     * Make a beginning form builder.
     *
     * @return Form
     */
    protected function beginningForm()
    {
        $content = new Content;
        $this->AdminContent($content);

        $form = new WidgetForm();
        $form->disablereset();
        $form->action(admin_urls('workflow', 'beginning'));

        $results = [];

        if (is_null($results = old('workflow_tables'))) {
            $workflowTables = WorkflowTable::with(['workflow', 'custom_table'])->get()
            ->filter(function ($workflowTable) {
                if (!boolval($workflowTable->workflow->setting_completed_flg)) {
                    return false;
                }

                return true;
            });

            // get all "common" and settinged workflows
            $workflowCommons = Workflow::allRecords(function ($workflow) {
                if ($workflow->workflow_type != WorkflowType::COMMON) {
                    return false;
                }

                if (!boolval($workflow->setting_completed_flg)) {
                    return false;
                }

                return true;
            });

            // get all custom tables
            $custom_tables = CustomTable::allRecords(function ($custom_table) {
                return !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())
            && !in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_IGNORE_SAVED_AUTHORITY());
            });

            foreach ($custom_tables as $custom_table) {
                $results[$custom_table->id] = [
                'custom_table' => $custom_table,
                'workflows' => []
            ];

                // append already setting workflow table
                $workflowTables->filter(function ($workflowTable) use ($custom_table) {
                    if ($custom_table->id !== $workflowTable->custom_table->id) {
                        return false;
                    }

                    return true;
                })->each(function ($workflowTable) use (&$results, $custom_table) {
                    $workflow = $workflowTable->workflow;
                    $results[$custom_table->id]['workflows'][$workflow->id] = [
                    'workflow_view_name' => $workflow->workflow_view_name,
                    'active_start_date' => $workflowTable->active_start_date,
                    'active_end_date' => $workflowTable->active_end_date,
                    'active_flg' => $workflowTable->active_flg,
                ];
                });

                // append common workflows
                $workflowCommons->each(function ($workflow) use (&$results, $custom_table) {
                    if (array_has($results[$custom_table->id]['workflows'], $workflow->id)) {
                        return;
                    }

                    $results[$custom_table->id]['workflows'][$workflow->id] = [
                    'workflow_view_name' => $workflow->workflow_view_name,
                ];
                    ;
                });
            }
        }

        // add form
        $form->description(exmtrans('workflow.help.beginning') . '<br />' . exmtrans('common.help.more_help'));

        $form->html(view('exment::workflow.beginning', [
            'items' => $results
        ])->render());

        $box = new Box(exmtrans('workflow.beginning'), $form);
        $box->tools(view('exment::tools.button', [
            'href' => admin_url('workflow'),
            'label' => trans('admin.list'),
            'icon' => 'fa-list',
        ])->render());

        $content->row($box);
        return $content;
    }

    /**
     * save beginning info
     *
     * @return Form
     */
    protected function beginningPost(Request $request)
    {
        $workflow_tables = $request->get('workflow_tables');

        //workflow validation
        $validator = \Validator::make($request->all(), [
            'workflow_tables.*.workflows.*.active_start_date' => ['nullable', 'date', 'before_or_equal:workflow_tables.*.workflows.*.active_end_date'],
            'workflow_tables.*.workflows.*.active_end_date' => ['nullable', 'date']
        ]);

        $errors = $validator->errors();

        foreach ($workflow_tables as $custom_table_id => $item) {
            // get active_flg's count
            $workflows = array_get($item, 'workflows', []);
            $active_workflows = collect($workflows)->filter(function ($workflow_item) {
                return boolval(array_get($workflow_item, 'active_flg'));
            });
            if ($active_workflows->count() >= 2) {
                // check date
                $searchDates = $active_workflows->map(function ($workflow_item) {
                    return [
                        'start' => Carbon::parse(array_get($workflow_item, 'active_start_date') ?? '1900-01-01'),
                        'end' => Carbon::parse(array_get($workflow_item, 'active_end_date') ?? '9999-12-31'),
                    ];
                });

                if (hasDuplicateDate($searchDates)) {
                    $errors->add("workflow_tables.$custom_table_id", exmtrans('workflow.message.same_custom_table'));
                }
            }
        }

        if (count($errors->getMessages()) > 0) {
            return back()->withErrors($errors)
                        ->withInput();
        }

        \DB::transaction(function () use ($workflow_tables) {
            foreach ($workflow_tables as $custom_table_id => $item) {
                foreach (array_get($item, 'workflows', []) as $workflow_id => $workflow_item) {
                    // get workflow table using custom table id and workflow id
                    $workflow_table = WorkflowTable::firstOrNew(['custom_table_id' => $custom_table_id, 'workflow_id' => $workflow_id]);
    
                    // if active, set each parameters
                    if (boolval(array_get($workflow_item, 'active_flg'))) {
                        $workflow_table->active_flg = true;
                        $workflow_table->active_start_date = array_get($workflow_item, 'active_start_date');
                        $workflow_table->active_end_date = array_get($workflow_item, 'active_end_date');
                    }
                    // not active, reset
                    else {
                        $workflow_table->active_flg = false;
                        $workflow_table->active_start_date = null;
                        $workflow_table->active_end_date = null;
                    }

                    $workflow_table->save();
                }
            }
        });
        
        admin_toastr(trans('admin.save_succeeded'));
        return back();
    }

    public function appendActivateButton($workflow, $tools)
    {
        if (isset($workflow) && $workflow->canActivate()) {
            $tools->append(new Tools\ModalMenuButton(
                admin_urls('workflow', $workflow->id, 'activateModal'),
                [
                    'label' => exmtrans('workflow.setting_complete'),
                    'button_class' => 'btn-success',
                    'icon' => 'fa-check-square',
                ]
            ));
        }
    }

    /**
     * Append table setting button
     */
    public function appendTableSettingButton($workflow, $tools)
    {
        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $tools->append(view('exment::tools.button', [
                'href' => admin_url('workflow/beginning'),
                'label' => exmtrans('workflow.beginning'),
                'icon' => 'fa-cog',
                'btn_class' => 'btn-primary',
            ]));
        }
    }

    public function disableDelete($workflow, $tools)
    {
        if (isset($workflow) && $workflow->disabled_delete) {
            $tools->disableDelete();
        }
    }
    /**
     * Activate workflow
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function activate(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        if (!$workflow->canActivate()) {
            return back();
        }

        $validator = \Validator::make($request->all(), [
            'activate_keyword' => Rule::in([Define::YES_KEYWORD]),
        ]);

        if (!$validator->passes()) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => exmtrans('error.mistake_keyword'),
                'errors' => [],
            ]);
        }

        $workflow->setting_completed_flg = true;
        $workflow->save();

        // Add Notify
        if (boolval($request->get('add_notify_flg', false))) {
            $this->appendWorkflowNotify($workflow);
        }
        
        return response()->json([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'redirect' => admin_url('workflow/beginning'),
        ]);
    }

    protected function appendWorkflowNotify($workflow)
    {
        // get mail template
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)
            ::where('value->mail_key_name', MailKeyName::WORKFLOW_NOTIFY)
            ->first();

        if (!isset($mail_template)) {
            return;
        }

        // insert
        $notify = new Notify;
        $notify->notify_view_name = exmtrans('notify.notify_trigger_options.workflow');
        $notify->notify_trigger = NotifyTrigger::WORKFLOW;
        $notify->workflow_id = $workflow->id;
        $notify->notify_actions = NotifyAction::SHOW_PAGE;
        $notify->action_settings = [
            'mail_template_id' => $mail_template->id,
            'notify_action_target' =>  [NotifyActionTarget::WORK_USER]
        ];
        $notify->save();
    }

    /**
     * validate before save.
     */
    protected function validateData(Form $form)
    {
        $request = request();

        $data = $request->all();

        // simple validation
        $keys = collect([
            "action_name" => 'required|max:30',
            "status_from" => 'required',
            "work_conditions" => 'required',
            "work_targets" => 'required',
            "flow_next_type" => 'required',
            "flow_next_count" => 'required|numeric|min:0|max:10',
            "comment_type" => 'required',
        ]);
        $validation = $keys->mapWithKeys(function ($v, $k) {
            return ["workflow_actions.*.$k" => $v];
        })->toArray();

        $attributes = $keys->mapWithKeys(function ($v, $k) {
            return ["workflow_actions.*.$k" => exmtrans("workflow.$k")];
        })->toArray();

        $validator = \Validator::make($data, $validation, [], $attributes);
        $errors = $validator->errors();

        // especially validation
        $workflow_actions = array_get($data, 'workflow_actions', []);
        foreach ($workflow_actions as $key => $workflow_action) {
            if (boolval(array_get($workflow_action, Form::REMOVE_FLAG_NAME))) {
                continue;
            }
            
            $errorKey = "workflow_actions.$key";

            // validate action conditions
            $workflow_conditions = Condition::getWorkConditions(array_get($workflow_action, 'work_conditions'));
            
            foreach ($workflow_conditions as $workflow_condition) {
                if (array_get($workflow_condition, 'status_to') == array_get($workflow_action, 'status_from')) {
                    $errors->add("$errorKey.status_from", exmtrans("workflow.message.same_action"));
                    break;
                }
            }


            // validate workflow targets
            $work_targets = jsonToArray(array_get($workflow_action, 'work_targets'));
            if (is_nullorempty($work_targets)) {
                $errors->add("$errorKey.work_targets", trans("validation.required", ['attribute' => exmtrans('workflow.work_targets')]));
            } elseif (array_get($work_targets, 'work_target_type') == WorkflowWorkTargetType::FIX) {
                array_forget($work_targets, 'work_target_type');
                if (is_nullorempty($work_targets) || !collect($work_targets)->contains(function ($work_target) {
                    return !is_nullorempty($work_target);
                })) {
                    $errors->add("$errorKey.work_targets", trans("validation.required", ['attribute' => exmtrans('workflow.work_targets')]));
                }
            } elseif (array_get($work_targets, 'work_target_type') == WorkflowWorkTargetType::ACTION_SELECT) {
                // if contains other FIX action in same acthion
                foreach ($workflow_actions as $validateIndex => $workflow_action_validate) {
                    if ($key == $validateIndex) {
                        continue;
                    }

                    if (array_get($workflow_action, 'status_from') != array_get($workflow_action_validate, 'status_from')) {
                        continue;
                    }

                    $work_targets_validate = jsonToArray(array_get($workflow_action_validate, 'work_targets'));
            
                    if (array_get($work_targets_validate, 'work_target_type') == array_get($work_targets, 'work_target_type')) {
                        continue;
                    }
        
                    $errors->add("$errorKey.work_targets", exmtrans("workflow.message.fix_and_action_select"));
                    break;
                }
            }
        }

        if (count($errors->getMessages()) > 0) {
            return back()->withErrors($errors)
                        ->withInput();
        }
    }

    protected function getProgressInfo($workflow, $action)
    {
        $id = $workflow->id ?? null;

        $steps = [];
        $hasAction = false;
        $workflow_action_url = null;
        $workflow_status_url = null;
        if (isset($id)) {
            $hasAction = WorkflowAction::where('workflow_id', $id)->count() > 0;
            $workflow_action_url = admin_urls('workflow', $id, 'edit?action=2');
            $workflow_status_url = admin_urls('workflow', $id, 'edit');
        }
        
        $steps[] = [
            'active' => ($action == 1),
            'complete' => false,
            'url' => ($action != 1)? $workflow_status_url: null,
            'description' => exmtrans('workflow.workflow_statuses')
        ];

        $steps[] = [
            'active' => ($action == 2),
            'complete' => false,
            'url' => ($action != 2)? $workflow_action_url: null,
            'description' => exmtrans('workflow.workflow_actions')
        ];

        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $steps[] = [
                'active' => ($action == 3),
                'complete' => false,
                'url' => ($action != 3) ? admin_url('workflow/beginning') : null,
                'description' => exmtrans('workflow.beginning'),
            ];
        }
        
        return $steps;
    }

    /**
     * Get target modal html
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function targetModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $custom_table = $workflow->getDesignatedTable();

        // get selected value
        $value = $request->get('workflow_actions_work_targets');
        $value = jsonToArray($value);

        $index = $request->get('index');

        $form = AuthUserOrgHelper::getUserOrgModalForm($custom_table, $value, [
            'prependCallback' => function ($form) use ($workflow, $value, $index) {
                if ($index > 0) {
                    $options = [
                        WorkflowWorkTargetType::ACTION_SELECT => WorkflowWorkTargetType::ACTION_SELECT()->transKey('workflow.work_target_type_options'),
                        WorkflowWorkTargetType::FIX => WorkflowWorkTargetType::FIX()->transKey('workflow.work_target_type_options')
                    ];
                    $help = exmtrans('workflow.help.work_targets2');
                    $default = WorkflowWorkTargetType::FIX;
                    $form->radio('work_target_type', exmtrans('workflow.work_targets'))
                        ->help($help)
                        ->attribute(['data-filtertrigger' =>true])
                        ->default(array_get($value, 'work_target_type') ?? $default)
                        ->options($options);
                } else {
                    $form->hidden('work_target_type')->default(WorkflowWorkTargetType::FIX);
                }
            }
        ]);

        // set custom column
        if (isset($custom_table)) {
            $options = $custom_table->custom_columns()
                ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
                ->indexEnabled()
                ->pluck('column_view_name', 'id');

            $form->multipleSelect('modal_' . ConditionTypeDetail::COLUMN()->lowerkey(), exmtrans('common.custom_column'))
                ->options($options)
                ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
                ->default(array_get($value, ConditionTypeDetail::COLUMN()->lowerkey()));
        }

        // set workflow system column
        $modal_system_default = array_get($value, SystemTableName::SYSTEM()->lowerkey());
        if (!isset($modal_system_default)) {
            $modal_system_default = (!isset($value) && $index == 0 ? [WorkflowTargetSystem::CREATED_USER] : null);
        }
        $form->multipleSelect('modal_' . ConditionTypeDetail::SYSTEM()->lowerkey(), exmtrans('common.system'))
            ->options(WorkflowTargetSystem::transKeyArray('common'))
            ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
            ->default($modal_system_default);

        $form->hidden('valueModalUuid')->default($request->get('widgetmodal_uuid'));

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
    public function conditionModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $custom_table = $workflow->getDesignatedTable();
        $statusOptions = $workflow->getStatusOptions();
        $workflow_type = WorkflowType::getEnum($workflow->workflow_type);

        // get selected value
        $value = $request->get('workflow_actions_work_conditions');
        $value = Condition::getWorkConditions($value);

        $form = new ModalForm($value);

        if (isset($workflow_type)) {
            $form->description(exmtrans('workflow.help.work_conditions_' . $workflow_type->lowerKey()))
                ->setWidth(10, 2);
        }

        // set range.
        $range = ($workflow_type == WorkflowType::COMMON) ? range(0, 0) : range(0, 2);
        foreach ($range as $index) {
            $work_condition = array_get($value, $index, []);
            if ($workflow_type == WorkflowType::TABLE) {
                $label = exmtrans('workflow.condition') .  ($index + 1);
                $form->exmheader($label)
                    ->hr();
            }

            if ($index === 0) {
                $form->hidden("enabled_flg_{$index}")
                ->default(1);
            } else {
                $form->checkboxone("enabled_flg_{$index}", 'enabled')
                ->setLabelClass(['invisible'])
                ->setWidth(10, 2)
                ->default(array_get($work_condition, 'enabled_flg', 0))
                ->attribute(['data-filtertrigger' =>true])
                ->option(['1' => exmtrans('common.available')]);
            }
            
            $form->select("status_to_{$index}", exmtrans('workflow.status_to'))
                ->options($statusOptions)
                ->required()
                ->default(array_get($work_condition, "status_to"))
                ->setElementClass('work_conditions_status_to')
                ->attribute(['data-filter' => json_encode(['key' => "enabled_flg_{$index}", 'value' => '1'])])
                ->setWidth(4, 2);

            if (isset($custom_table)) {
                $default = array_get($work_condition, "workflow_conditions", []);
                
                // filter setting
                $hasManyTable = new ConditionHasManyTable($form, [
                    'ajax' => admin_url("webapi/{$id}/filter-value"),
                    'name' => "workflow_conditions_{$index}",
                    'linkage' => json_encode(['condition_key' => admin_urls('webapi', $custom_table->table_name, 'filter-condition')]),
                    'targetOptions' => $custom_table->getColumnsSelectOptions([
                        'include_system' => false,
                        'ignore_attachment' => true,
                    ]),
                    'custom_table' => $custom_table,
                    'filterKind' => FilterKind::WORKFLOW,
                ]);

                $hasManyTable->callbackField(function ($field) use ($default, $index) {
                    $field->setRelatedValue($default)
                        ->disableHeader()
                        ->attribute(['data-filter' => json_encode(['key' => "enabled_flg_{$index}", 'value' => '1'])])
                    ;
                });

                $hasManyTable->render();
            }
        }

        $form->hidden('valueModalUuid')->default($request->get('widgetmodal_uuid'));            // add message
        $form->hidden('has_condition')->default(exmtrans('workflow.has_condition'));

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans("workflow.work_conditions"),
            'showReset' => true,
            'modalSize' => ($workflow_type == WorkflowType::COMMON) ? 'modal-lg' : 'modal-xl',
            'submitlabel' => trans('admin.setting'),
            'contentname' => 'workflow_actions_work_conditions',
        ]);
    }
    
    /**
     * Render Setting modal form.
     *
     * @return Content
     */
    public function activateModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $activatePath = admin_urls('workflow', $id, 'activate');
        // create form fields
        $form = new ModalForm();
        $form->action($activatePath);

        $form->description(exmtrans('workflow.help.setting_complete'));

        $form->text('activate_keyword', exmtrans('common.keyword'))
            ->required()
            ->help(exmtrans('common.message.input_keyword', Define::YES_KEYWORD));

        $form->switchbool('add_notify_flg', exmtrans("workflow.add_notify_flg"))->help(exmtrans('workflow.help.add_notify_flg'));

        $form->setWidth(9, 2);
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('workflow.setting_complete')
        ]);
    }
}

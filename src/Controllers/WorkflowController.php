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
use Exceedone\Exment\Model\System;
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
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class WorkflowController extends AdminControllerBase
{
    use WorkflowTrait;
    use HasResourceActions{
        HasResourceActions::destroy as destroyTrait;
    }

    protected $exists = false;

    public function __construct()
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
        $grid = new Grid(new Workflow());
        $grid->column('id', exmtrans("common.id"));
        $grid->column('workflow_type', exmtrans("workflow.workflow_type"))->display(function ($v) {
            return WorkflowType::getEnum($v)->transKey('workflow.workflow_type_options');
        });
        $grid->column('workflow_tables', exmtrans("custom_table.table"))->display(function ($v, $column, $model) {
            if (is_null($custom_table = $model->getDesignatedTable())) {
                return null;
            }

            return $custom_table->table_view_name;
        });
        $grid->column('workflow_view_name', exmtrans("workflow.workflow_view_name"))->sortable();
        $grid->column('workflow_statuses', exmtrans("workflow.status_name"))->display(function ($value, $column, $workflow) {
            return $workflow->getStatusesString();
        });
        $grid->column('setting_completed_flg', exmtrans("workflow.setting_completed_flg"))->display(function ($value) {
            if (boolval($value)) {
                return \Exment::getTrueMark($value);
            }

            return null;
        })->escape(false);

        $grid->disableExport();
        if (!\Exment::user()->hasPermission(Permission::WORKFLOW)) {
            $grid->disableCreateButton();
        }

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
            $actions->disableEdit();

            if ($actions->row->setting_completed_flg) {
                $actions->disableDelete();
                $actions->prepend((new Tools\ModalLink(
                    admin_url("workflow/{$actions->row->id}/deactivateModal"),
                    [
                        'icon' => 'fa-trash',
                        'modal_title' => trans('admin.delete'),
                        'attributes' => [
                            'data-toggle' => "tooltip",
                        ],
                    ]
                ))->render());
            }

            /** @phpstan-ignore-next-line fix laravel-admin documentation */
            if ($actions->row->canActivate()) {
                $actions->prepend((new Tools\ModalLink(
                    admin_urls('workflow', $actions->row->id, 'activateModal'),
                    [
                        'icon' => 'fa-check-square',
                        'modal_title' => exmtrans('workflow.setting_complete'),
                        'attributes' => [
                            'data-toggle' => "tooltip",
                        ],
                    ]
                ))->render());
            }

            if ($actions->row->setting_completed_flg) {
                $linker = (new Linker())
                    ->url(admin_urls('workflow', $actions->getKey(), 'notify'))
                    ->icon('fa-bell')
                    ->tooltip(exmtrans('notify.header'));
                $actions->prepend($linker);
            }

            $linker = (new Linker())
                ->url(admin_urls('workflow', $actions->getKey(), 'edit?action=2'))
                ->icon('fa-exchange')
                ->tooltip(exmtrans('workflow.action'));
            $actions->prepend($linker);

            // add new edit link
            $linker = (new Linker())
                ->url(admin_urls('workflow', $actions->getKey(), 'edit?action=1'))
                ->icon('fa-edit')
                ->tooltip(trans('admin.edit'));
            $actions->prepend($linker);
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
            $tools->prepend(new Tools\SystemChangePageMenu());
        });

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->equal('workflow_type', exmtrans("workflow.workflow_type"))->select(function ($val) {
                return WorkflowType::transKeyArray('workflow.workflow_type_options');
            });

            $filter->like('workflow_view_name', exmtrans("workflow.workflow_view_name"));

            $filter->equal('setting_completed_flg', exmtrans("workflow.setting_completed_flg"))->radio(\Exment::getYesNoAllOption());
        });

        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @return Form|Content
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
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
                return $this->beginningForm();
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
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $this->isDeleteForce = true;
        return $this->destroyTrait($id);
    }

    /**
     * Make a action edit form builder.
     *
     * @return Form
     */
    protected function statusForm($id = null)
    {
        $workflow = Workflow::getEloquent($id);

        $form = new Form(new Workflow());
        $form->progressTracker()->options($this->getProgressInfo($workflow, 1));

        $form->descriptionHtml(exmtrans('common.help.more_help'));

        $isShowId = boolval(config('exment.show_workflow_id', false));

        if ($isShowId && !is_nullorempty($id)) {
            $form->display('id', 'ID');
        }

        $form->text('workflow_view_name', exmtrans("workflow.workflow_view_name"))
            ->required()
            ->rules("max:40");

        // is create
        if (!isset($workflow)) {
            $form->select('workflow_type', exmtrans('workflow.workflow_type'))
                ->options(WorkflowType::transKeyArray('workflow.workflow_type_options'))
                ->attribute(['data-filtertrigger' =>true])
                ->disableClear()
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
                ->escape(false)
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

        $field = $form->hasManyTable('workflow_statuses', exmtrans("workflow.workflow_statuses"), function ($form) use ($isShowId) {
            if ($isShowId) {
                $form->display('id', 'ID')->displayClass('p-0 text-center');
            }
            $form->text('status_name', exmtrans("workflow.status_name"))->help(exmtrans('workflow.help.status_name'));
            $form->switchbool('datalock_flg', exmtrans("workflow.datalock_flg"))->help(exmtrans('workflow.help.datalock_flg'));
            $form->hidden('order')->default(0);
        })->setTableWidth(8, 2)
        ->required();

        if ($isShowId) {
            $field->setTableColumnWidth(1, 5, 2, 2);
        } else {
            $field->setTableColumnWidth(6, 2, 2);
        }

        if (isset($workflow) && boolval($workflow->setting_completed_flg)) {
            $field->disableOptions();
        } else {
            $field->rowUpDown('order')
                ->descriptionHtml(sprintf(exmtrans("workflow.description_workflow_statuses")));
        }

        $form->saving(function (Form $form) {
            $this->exists = $form->model()->exists;
        });

        $form->savedInTransaction(function (Form $form) {
            /** @var Workflow $model */
            $model = $form->model();

            // get workflow_statuses and set completed fig
            $statuses = $model->workflow_statuses()->get()->sortByDesc('order')->values();

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
            $tools->append(new Tools\SystemChangePageMenu());
            $self->appendActivateButton($workflow, $tools);
            $self->appendTableSettingButton($workflow, $tools);
            $self->disableDelete($workflow, $tools);
        });

        $form->saved(function (Form $form) {
            /** @var Workflow $model */
            $model = $form->model();

            // redirect workflow action page
            if (!$this->exists) {
                $workflow_action_url = admin_urls('workflow', $model->id, 'edit?action=2');

                admin_toastr(exmtrans('workflow.help.saved_redirect_column'));
                return redirect($workflow_action_url);
            }
        });

        if (isset($workflow)) {
            $form->submitRedirect([
                'key' => 'action_2',
                'value' => 'action_2',
                'label' => exmtrans('common.redirect_to', exmtrans('workflow.workflow_actions')),
                'redirect' => function ($resourcesPath, $key) {
                    return redirect(admin_urls('workflow', $key, 'edit?action=2&after-save=action_2'));
                },
            ])->submitRedirect([
                'key' => 'continue_editing',
                'value' => 1,
                'label' => trans('admin.continue_editing'),
                'redirect' => function ($resourcesPath, $key) {
                    return redirect(admin_urls('workflow', $key, 'edit?action=1&after-save=1'));
                },
            ]);
        }

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

        $form = new Form(new Workflow());
        $form->progressTracker()->options($this->getProgressInfo($workflow, 2));

        $form->descriptionHtml(exmtrans('common.help.more_help'));

        $form->hidden('action')->default(2);
        $form->display('workflow_view_name', exmtrans("workflow.workflow_view_name"));
        $form->display('workflow_status', exmtrans("workflow.status_name"))
            ->default($workflow->getStatusesString());

        if ($workflow->workflow_type == WorkflowType::TABLE) {
            $custom_table = $workflow->getDesignatedTable();
            $form->display('custom_table_id', exmtrans('custom_table.table'))
                ->default($custom_table->table_view_name ?? null);
        }

        $form->embeds('options', function ($form) {
            $form->switchbool('workflow_edit_flg', exmtrans("workflow.workflow_edit_flg"))
                ->help(exmtrans("workflow.help.workflow_edit_flg"))
                ->default("0")
            ;

            $form->select('get_by_userinfo_base', exmtrans('workflow.get_by_userinfo_base'))
                ->options([
                    'first_executed_user' => exmtrans('workflow.first_executed_user'),
                    'executed_user' => exmtrans('workflow.executed_user'),
                    'created_user' => exmtrans('workflow.created_user'),
                ])
                ->help(exmtrans('workflow.help.get_by_userinfo_base'))
                ->disableClear()
                ->required()
                ->default('executed_user')
            ;
        })->disableHeader();

        $field = $form->hasManyTable('workflow_actions', exmtrans("workflow.workflow_actions"), function ($form) use ($id, $workflow) {
            $form->workflowStatusSelects('status_from', exmtrans("workflow.status_name"))
                ->disableClear()
                ->options(function ($value, $field, $workflow) {
                    return $workflow->getStatusOptions($field->getIndex() === 0);
                });

            if ($workflow->workflow_type == WorkflowType::TABLE) {
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
            } else {
                $form->select('work_condition_select', exmtrans('workflow.status_to'))
                    ->options($workflow->getStatusOptions())
                    ->help(exmtrans('workflow.help.status_to'))
                    ->disableClear()
                    ->required();
            }

            $default = exmtrans("workflow.work_target_type_options.fix");
            $form->valueModal('work_targets', exmtrans("workflow.work_targets"))
                ->ajax(admin_urls('workflow', $id, 'modal', 'target'))
                ->modalContentname('workflow_actions_work_targets')
                ->setElementClass('workflow_actions_work_targets')
                ->buttonClass('btn-sm btn-default')
                ->valueTextScript("Exment.WorkflowEvent.GetSettingValText('$default');")
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

                    $label = null;
                    if (array_get($value, 'work_target_type') == WorkflowWorkTargetType::ACTION_SELECT) {
                        $label = WorkflowWorkTargetType::ACTION_SELECT()->transKey('workflow.work_target_type_options');
                    } elseif (array_get($value, 'work_target_type') == WorkflowWorkTargetType::FIX) {
                        $label = WorkflowWorkTargetType::FIX()->transKey('workflow.work_target_type_options');
                    } elseif (array_get($value, 'work_target_type') == WorkflowWorkTargetType::GET_BY_USERINFO) {
                        $label = WorkflowWorkTargetType::GET_BY_USERINFO()->transKey('workflow.work_target_type_options') ;
                    }
                    $label = "[{$label}]";

                    if (array_get($value, 'work_target_type') != WorkflowWorkTargetType::ACTION_SELECT) {
                        $text = collect(WorkflowAuthority::getAuhoritiesFromValue($value))
                            ->filter()->map(function ($authority) {
                                return esc_html($authority->authority_text);
                            })->implode('<br/>');
                        return $label . (!is_nullorempty($text) ? "<br/>" : "") . $text;
                    }
                    return $label;

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
                ->escape(false)
            ;

            $form->workflowOptions('options', exmtrans("workflow.option"));
        })->setTableColumnWidth(3, 2, 3, 3, 1)
           ->setRelatedValue([[]])
           ->required()
           ->hideDeleteButtonRow(1);

        $self = $this;
        $form->tools(function (Form\Tools $tools) use ($self, $workflow) {
            $tools->append(new Tools\SystemChangePageMenu());
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

        $form->editing(function ($form, $arr) use ($workflow) {
            foreach ($form->model()->workflow_actions as $workflow_action) {
                $workflow_action->append([$workflow->workflow_type == WorkflowType::TABLE ? 'work_conditions' : 'work_condition_select']);
            }
        });

        $form->submitRedirect([
            'key' => 'action_1',
            'value' => 'action_1',
            'label' => exmtrans('common.redirect_to', exmtrans('workflow.workflow_statuses')),
            'redirect' => function ($resourcesPath, $key) {
                return redirect(admin_urls('workflow', $key, 'edit?action=1&after-save=action_1'));
            },
        ])->submitRedirect([
            'key' => 'continue_editing',
            'value' => 1,
            'label' => trans('admin.continue_editing'),
            'redirect' => function ($resourcesPath, $key) {
                return redirect(admin_urls('workflow', $key, 'edit?action=2&after-save=1'));
            },
        ]);

        return $form;
    }

    /**
     * Make a beginning form builder.
     *
     * @return Content
     */
    protected function beginningForm()
    {
        $content = new Content();
        $this->AdminContent($content);

        $form = new WidgetForm();
        $form->disablereset();
        $form->action(admin_urls('workflow', 'beginning'));

        $results = [];

        /** @phpstan-ignore-next-line Call to function is_null() with mixed will always evaluate to false. */
        if (is_null($results = old('workflow_tables'))) {
            $workflowTables = WorkflowTable::with(['workflow', 'custom_table'])->get()
            ->filter(function ($workflowTable) {
                if (!isset($workflowTable->workflow)) {
                    return false;
                }

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
        $form->descriptionHtml(exmtrans('workflow.help.beginning') . '<br />' . exmtrans('common.help.more_help'));

        $form->html(view('exment::workflow.beginning', [
            'items' => $results
        ])->render());

        $box = new Box(exmtrans('workflow.beginning'), $form);
        $box->tools(view('exment::tools.button', [
            'href' => admin_url('workflow'),
            'label' => trans('admin.list'),
            'icon' => 'fa-list',
        ])->render());
        $box->tools(new Tools\SystemChangePageMenu());

        $content->row($box);
        return $content;
    }

    /**
     * save beginning info
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function beginningPost(Request $request)
    {
        $workflow_tables = $request->get('workflow_tables');

        //workflow validation
        $validator = \Validator::make($request->all(), [
            'workflow_tables.*.workflows.*.active_start_date' => ['nullable', 'date', 'before_or_equal:workflow_tables.*.workflows.*.active_end_date'],
            'workflow_tables.*.workflows.*.active_end_date' => ['nullable', 'date']
        ], [], [
            'workflow_tables.*.workflows.*.active_start_date' => exmtrans('workflow.active_start_date'),
            'workflow_tables.*.workflows.*.active_end_date' => exmtrans('workflow.active_end_date'),
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

        \ExmentDB::transaction(function () use ($workflow_tables) {
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

            $tools->prepend((new Tools\ModalMenuButton(
                admin_url("workflow/{$workflow->id}/deactivateModal"),
                [
                    'icon' => 'fa-trash',
                    'label' => trans('admin.delete'),
                    'button_class' => 'btn-danger',
                    'modal_title' => trans('admin.delete'),
                    'attributes' => [
                        'data-toggle' => "tooltip",
                    ]
                ]
            ))->render());
        }
    }

    /**
     * Activate workflow
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|Response
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

    /**
     * deactivate workflow
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|Response
     */
    public function deactivate(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        if (!$workflow || !$workflow->setting_completed_flg) {
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

        // Set deleted_at directrly. Because cannot delete if has children columns (ex. worlflow_statuses)
        $workflow->update([
            'deleted_at' => \Carbon\Carbon::now(),
        ]);

        return response()->json([
            'result'  => true,
            'toastr' => trans('admin.save_succeeded'),
            'redirect' => admin_url('workflow'),
        ]);
    }

    protected function appendWorkflowNotify($workflow)
    {
        // get mail template
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::WORKFLOW_NOTIFY)
            ->first();

        if (!isset($mail_template)) {
            return;
        }

        // insert
        $notify = new Notify();
        $notify->notify_view_name = exmtrans('notify.notify_trigger_options.workflow');
        $notify->notify_trigger = NotifyTrigger::WORKFLOW;
        $notify->target_id = $workflow->id;
        $notify->mail_template_id = $mail_template->id;
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' =>  [NotifyActionTarget::WORK_USER]
        ]];
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
            "work_targets" => 'required',
            "flow_next_type" => 'required',
            "flow_next_count" => 'required|numeric|min:0|max:10',
            "comment_type" => 'required',
        ]);

        /** @var Workflow $model */
        $model = $form->model();
        $isWorkflowTypeTable = $model->workflow_type == WorkflowType::TABLE;
        $key_condition = $isWorkflowTypeTable ? "work_conditions" : "work_condition_select";
        $keys->put($key_condition, "required");

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

            if ($isWorkflowTypeTable) {
                foreach ($workflow_conditions as $workflow_condition) {
                    if (array_get($workflow_condition, 'status_to') == array_get($workflow_action, 'status_from')) {
                        $errors->add("$errorKey.status_from", exmtrans("workflow.message.same_action"));
                        break;
                    }
                }
            } else {
                if (array_get($workflow_action, 'work_condition_select') == array_get($workflow_action, 'status_from')) {
                    $errors->add("$errorKey.status_from", exmtrans("workflow.message.same_action"));
                    break;
                }
            }

            // validate workflow targets
            $work_targets = jsonToArray(array_get($workflow_action, 'work_targets'));
            $work_target_type = array_get($work_targets, 'work_target_type');
            if (is_nullorempty($work_targets)) {
                $errors->add("$errorKey.work_targets", trans("validation.required", ['attribute' => exmtrans('workflow.work_targets')]));
            } else {
                // Check work target type
                if ($work_target_type == WorkflowWorkTargetType::FIX || $work_target_type == WorkflowWorkTargetType::GET_BY_USERINFO) {
                    // Check validation required
                    array_forget($work_targets, 'work_target_type');
                    if (is_nullorempty($work_targets) || !collect($work_targets)->contains(function ($work_target) {
                        return !is_nullorempty($work_target);
                    })) {
                        $errors->add("$errorKey.work_targets", trans("validation.required", ['attribute' => exmtrans('workflow.work_targets')]));
                    }
                }

                // if contains other FIX action in same acthion
                foreach ($workflow_actions as $validateIndex => $workflow_action_validate) {
                    if ($key == $validateIndex) {
                        continue;
                    }

                    if (array_get($workflow_action, 'status_from') != array_get($workflow_action_validate, 'status_from')) {
                        continue;
                    }

                    // It's ok if ignore_work
                    if (array_boolval($workflow_action_validate, 'ignore_work')) {
                        continue;
                    }

                    $work_targets_validate = jsonToArray(array_get($workflow_action_validate, 'work_targets'));

                    if ($work_target_type == WorkflowWorkTargetType::ACTION_SELECT) {
                        if (array_get($work_targets_validate, 'work_target_type') == array_get($work_targets, 'work_target_type')) {
                            continue;
                        }
                        $errors->add("$errorKey.{$key_condition}", exmtrans("workflow.message." . array_get($work_targets_validate, 'work_target_type') . "_and_action_select"));
                    }
                    break;
                }
            }

            // Cannnot select ACTION_SELECT and ignore_work
            if ($work_target_type == WorkflowWorkTargetType::ACTION_SELECT) {
                // It's ok if ignore_work
                if (array_boolval($workflow_action, 'ignore_work')) {
                    $errors->add("$errorKey.work_targets", exmtrans("workflow.message.ignore_work_and_action_select"));
                    break;
                }
            }
        }

        if (count($errors->getMessages()) > 0) {
            return back()->withErrors($errors)
                        ->withInput();
        }
    }

    /**
     * Get target modal html
     *
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function targetModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $custom_table = $workflow->getDesignatedTable();

        // get selected value
        $value = $request->get('workflow_actions_work_targets');
        $value = jsonToArray($value);

        $index = $request->get('index');

        $form = $this->getUserOrgModalForm($custom_table, $workflow, $value, [
            'prependCallback' => function ($form) use ($value, $index) {
                if ($index > 0) {
                    $options = [
                        WorkflowWorkTargetType::ACTION_SELECT => WorkflowWorkTargetType::ACTION_SELECT()->transKey('workflow.work_target_type_options'),
                        WorkflowWorkTargetType::FIX => WorkflowWorkTargetType::FIX()->transKey('workflow.work_target_type_options'),
                        WorkflowWorkTargetType::GET_BY_USERINFO => WorkflowWorkTargetType::GET_BY_USERINFO()->transKey('workflow.work_target_type_options'),
                    ];
                    $help = exmtrans('workflow.help.work_targets2');
                    $default = WorkflowWorkTargetType::FIX;
                    $form->radio('work_target_type', exmtrans('workflow.work_targets'))
                        ->help($help)
                        ->attribute(['data-filtertrigger' =>true])
                        ->default(array_get($value, 'work_target_type') ?? $default)
                        ->options($options);

                    ///// Select by userinfo
                    $options = CustomTable::getEloquent(SystemTableName::USER)->custom_columns()
                        ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
                        ->indexEnabled()
                        ->pluck('column_view_name', 'id');

                    $form->multipleSelect('modal_' . ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerkey(), exmtrans('common.custom_column'))
                        ->options($options)
                        ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => WorkflowWorkTargetType::GET_BY_USERINFO])])
                        ->help(exmtrans('workflow.help.target_column_get_by_userinfo'))
                        ->required()
                        ->default(array_get($value, ConditionTypeDetail::LOGIN_USER_COLUMN()->lowerkey()));
                } else {
                    $form->hidden('work_target_type')->default(WorkflowWorkTargetType::FIX);
                    $work_target_type_label = exmtrans('workflow.work_target_type_options.'. WorkflowWorkTargetType::FIX);
                    $form->hidden('work_target_type_label')->default($work_target_type_label);
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
                ->help(exmtrans('workflow.help.target_column'))
                ->default(array_get($value, ConditionTypeDetail::COLUMN()->lowerkey()));
        }

        // set workflow system column
        $modal_system_default = array_get($value, SystemTableName::SYSTEM()->lowerkey());
        if (!isset($modal_system_default)) {
            $modal_system_default = (is_nullorempty($value) && $index == 0 ? [WorkflowTargetSystem::CREATED_USER] : null);
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
     * @param $id
     * @return Response
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
            $form->descriptionHtml(exmtrans('workflow.help.work_conditions_' . $workflow_type->lowerKey()))
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
                    'ajax' => admin_url("webapi/{$custom_table->table_name}/filter-value"),
                    'name' => "workflow_conditions_{$index}",
                    'linkage' => json_encode(['condition_key' => url_join($custom_table->table_name, 'filter-condition')]),
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

                $form->radio("condition_join_{$index}", exmtrans("condition.condition_join"))
                    ->options(exmtrans("condition.condition_join_options"))
                    ->attribute(['data-filter' => json_encode(['key' => "enabled_flg_{$index}", 'value' => '1'])])
                    ->default(array_get($work_condition, "condition_join") ?? 'and');

                $form->checkboxone("condition_reverse_{$index}", exmtrans("condition.condition_reverse"))
                    ->option(exmtrans("condition.condition_reverse_options"))
                    ->attribute(['data-filter' => json_encode(['key' => "enabled_flg_{$index}", 'value' => '1'])])
                    ->default(array_get($work_condition, "condition_reverse") ?? '0');
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
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function activateModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $activatePath = admin_urls('workflow', $id, 'activate');
        // create form fields
        $form = new ModalForm();
        $form->action($activatePath);

        $form->descriptionHtml(exmtrans('workflow.help.setting_complete'));

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

    /**
     * Render deactivate modal form.
     *
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function deactivateModal(Request $request, $id)
    {
        $workflow = Workflow::getEloquent($id);
        $activatePath = admin_urls('workflow', $id, 'deactivate');
        // create form fields
        $form = new ModalForm();
        $form->action($activatePath);

        $form->descriptionHtml(exmtrans('workflow.help.deactivate_complete'));

        $form->text('activate_keyword', exmtrans('common.keyword'))
            ->required()
            ->help(exmtrans('common.message.input_keyword', Define::YES_KEYWORD));

        $form->setWidth(9, 2);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('workflow.delete_complete')
        ]);
    }



    /**
     * Get User, org, role group form
     *
     * @return ModalForm
     */
    protected function getUserOrgModalForm(?CustomTable $custom_table, ?Workflow $workflow, $value = [], $options = [])
    {
        $options = array_merge([
            'prependCallback' => null
        ], $options);
        $isWfCommon = $workflow && $workflow->workflow_type == WorkflowType::COMMON;

        $form = new ModalForm();
        if (isset($options['prependCallback'])) {
            $options['prependCallback']($form);
        }

        list($users, $ajax) = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptionsAndAjaxUrl([
            'display_table' => $custom_table,
            'selected_value' => array_get($value, SystemTableName::USER),
        ]);

        // select target users
        $field = $form->multipleSelect('modal_' . SystemTableName::USER, exmtrans('menu.system_definitions.user'))
            ->options($users)
            ->ajax($ajax)
            ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
            ->default(array_get($value, SystemTableName::USER));
        // Set help if has $custom_table
        if (!$isWfCommon && $custom_table) {
            $field->help(exmtrans('workflow.help.target_user_org', [
                'table_view_name' => esc_html($custom_table->table_view_name),
                'type' => exmtrans('menu.system_definitions.user'),
            ]));
        }

        if (System::organization_available()) {
            list($organizations, $ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptionsAndAjaxUrl([
                'display_table' => $custom_table,
                'selected_value' => array_get($value, SystemTableName::ORGANIZATION),
            ]);

            $field = $form->multipleSelect('modal_' . SystemTableName::ORGANIZATION, exmtrans('menu.system_definitions.organization'))
                ->options($organizations)
                ->ajax($ajax)
                ->attribute(['data-filter' => json_encode(['key' => 'work_target_type', 'value' => 'fix'])])
                ->default(array_get($value, SystemTableName::ORGANIZATION));

            // Set help if has $custom_table
            if (!$isWfCommon && $custom_table) {
                $field->help(exmtrans('workflow.help.target_user_org', [
                    'table_view_name' => esc_html($custom_table->table_view_name),
                    'type' => exmtrans('menu.system_definitions.organization'),
                ]));
            }
        }

        return $form;
    }
}

<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Validator\EmailMultiline;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;

class NotifyController extends AdminControllerBase
{
    use HasResourceActions;
    use InitializeFormTrait;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("notify.header"), exmtrans("notify.header"), exmtrans("notify.description"), 'fa-bell');
    }


    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        if (!is_null($copy_id = $request->get('copy_id'))) {
            return $this->AdminContent($content)->body($this->form(null, $copy_id)->replicate($copy_id, ['notify_view_name']));
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
        $grid = new Grid(new Notify);
        $grid->column('notify_view_name', exmtrans("notify.notify_view_name"))->sortable();
        $grid->column('notify_trigger', exmtrans("notify.notify_trigger"))->sortable()->displayEscape(function ($val) {
            $enum = NotifyTrigger::getEnum($val);
            return $enum ? $enum->transKey('notify.notify_trigger_options') : null;
        });

        $grid->column('custom_table_id', exmtrans("notify.notify_target"))->sortable()->displayEscape(function ($val) {
            $custom_table = CustomTable::getEloquent($val);
            if (isset($custom_table)) {
                return $custom_table->table_view_name ?? null;
            }
            if (isset($this->workflow_id)) {
                return Workflow::getEloquent($this->workflow_id)->workflow_view_name ?? null;
            }

            return null;
        });

        $grid->column('action_settings', exmtrans("notify.notify_action"))->sortable()->displayEscape(function ($val) {
            return collect($val)->map(function ($v) {
                $enum = NotifyAction::getEnum(array_get($v, 'notify_action'));
                return isset($enum) ? $enum->transKey('notify.notify_action_options') : null;
            })->filter()->unique()->implode(exmtrans('common.separate_word'));
        });

        $grid->column('active_flg', exmtrans("plugin.active_flg"))->sortable()->display(function ($val) {
            return \Exment::getTrueMark($val);
        });

        // filter only custom table user has permission custom table
        if (!\Exment::user()->isAdministrator()) {
            $custom_tables = CustomTable::filterList()->pluck('id')->toArray();
            $grid->model()->whereIn('custom_table_id', $custom_tables);
        }

        $grid->tools(function (Grid\Tools $tools) {
            $tools->prepend(new Tools\SystemChangePageMenu());
        });

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
            
            $linker = (new Linker)
                ->url(admin_urls("notify/create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('notify.notify')));
            $actions->prepend($linker);
        });
        
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('notify_name', exmtrans("notify.notify_name"));
            $filter->like('notify_view_name', exmtrans("notify.notify_view_name"));

            $filter->equal('notify_trigger', exmtrans("notify.notify_trigger"))->select(function ($val) {
                return NotifyTrigger::transKeyArray("notify.notify_trigger_options");
            });
            
            $filter->equal('custom_table_id', exmtrans("notify.custom_table_id"))->select(function ($val) {
                return CustomTable::filterList()->pluck('table_view_name', 'id');
            });

            $filter->equal('workflow_id', exmtrans("notify.workflow_id"))->select(function ($val) {
                return Workflow::allRecords(function ($workflow) {
                    if (!boolval($workflow->setting_completed_flg)) {
                        return false;
                    }
                    return true;
                })->pluck('workflow_view_name', 'id');
            });

            $filter->equal('active_flg', exmtrans("plugin.active_flg"))->radio(\Exment::getYesNoAllOption());
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
        if (!$this->hasPermissionEdit($id)) {
            return;
        }

        $form = new Form(new Notify);
        $notify = Notify::find($id);

        if (!isset($notify) || is_nullorempty($notify->notify_name)) {
            $form->text('notify_name', exmtrans("notify.notify_name"))
                ->rules("max:30|nullable|unique:".Notify::getTableName()."|regex:/".Define::RULES_REGEX_SYSTEM_NAME."/")
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
        } else {
            $form->display('notify_name', exmtrans("notify.notify_name"));
        }

        $form->text('notify_view_name', exmtrans("notify.notify_view_name"))->required()->rules("max:40");
        // TODO: only role tables

        $form->switchbool('active_flg', exmtrans("plugin.active_flg"))
            ->help(exmtrans("notify.help.active_flg"))
            ->default(true);

        $form->exmheader(exmtrans('notify.header_trigger'))->hr();
        
        $form->select('notify_trigger', exmtrans("notify.notify_trigger"))
            ->options(NotifyTrigger::transKeyArray("notify.notify_trigger_options"))
            ->required()
            ->config('allowClear', false)
            ->attribute([
                'data-filtertrigger' =>true,
                'data-changedata' => json_encode([
                    'getitem' =>
                        ['uri' => admin_url('notify/notifytrigger_template')]
                ])
            ])
            ->help(exmtrans("notify.help.notify_trigger"));

        $form->select('custom_table_id', exmtrans("notify.custom_table_id"))
        ->required()
        ->options(function ($custom_table_id, $foo) {
            return CustomTable::filterList()->pluck('table_view_name', 'id');
        })->attribute([
            'data-linkage' => json_encode([
                'trigger_settings_notify_target_column' =>  admin_url('notify/targetcolumn'),
                'custom_view_id' => [
                  'url' => admin_url('webapi/table/filterviews'),
                  'text' => 'view_view_name',
                ]
            ]),
            'data-filter' => json_encode(['key' => 'notify_trigger', 'notValue' => NotifyTrigger::WORKFLOW])
        ])
        ->help(exmtrans("notify.help.custom_table_id"));

        $form->select('custom_view_id', exmtrans("notify.custom_view_id"))
            ->help(exmtrans("notify.help.custom_view_id"))
            ->options(function ($value, $field) {
                if (is_nullorempty($field)) {
                    return [];
                }
        
                // check $value or $field->data()
                $custom_table = null;
                if (isset($value)) {
                    $custom_view = CustomView::getEloquent($value);
                    $custom_table = $custom_view ? $custom_view->custom_table : null;
                } elseif (!is_nullorempty($field->data())) {
                    $custom_table = CustomTable::getEloquent(array_get($field->data(), 'custom_table_id'));
                }
        
                if (!isset($custom_table)) {
                    return [];
                }
                
                return $custom_table->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') == ViewKindType::FILTER;
                    })->pluck('view_view_name', 'id');
            })->attribute([
                'data-filter' => json_encode(['key' => 'notify_trigger', 'notValue' => NotifyTrigger::WORKFLOW]),
            ]);

        $form->select('workflow_id', exmtrans("notify.workflow_id"))
        ->required()
        ->options(function ($workflow_id) {
            return Workflow::allRecords(function ($workflow) {
                if (!boolval($workflow->setting_completed_flg)) {
                    return false;
                }
                return true;
            })->pluck('workflow_view_name', 'id');
        })->attribute([
            'data-filter' => json_encode(['key' => 'notify_trigger', 'value' => [NotifyTrigger::WORKFLOW]]),
            'data-linkage' =>json_encode([
                'action_settings_notify_action_target' => admin_url('notify/notify_action_target_workflow'),
            ]),
            'data-linkage-getdata' =>json_encode([
                ['key' => 'notify_trigger'],
            ])
        ])
        ->help(exmtrans("notify.help.workflow_id"));


        $form->embeds('trigger_settings', exmtrans("notify.trigger_settings"), function (Form\EmbeddedForm $form) use ($copy_id) {
            // Notify Time --------------------------------------------------
            $controller = $this;
            $form->select('notify_target_column', exmtrans("notify.notify_target_column"))
            ->options(function ($val) use ($controller, $copy_id) {
                if (!isset($val)) {
                    if (isset($copy_id)) {
                        $copy_notify = Notify::find($copy_id);
                        return $controller->getTargetColumnOptions($copy_notify->custom_table_id, false);
                    }
                    return [];
                }

                $custom_column = CustomColumn::getEloquent($val);
                if (!isset($custom_column)) {
                    return [];
                }
                
                return $controller->getTargetColumnOptions($custom_column->custom_table, false);
            })
            ->required()
            ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
            ->help(exmtrans("notify.help.trigger_settings"));

            $form->number('notify_day', exmtrans("notify.notify_day"))
                ->help(exmtrans("notify.help.notify_day"))
                ->min(0)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ;
            $form->select('notify_beforeafter', exmtrans("notify.notify_beforeafter"))
                ->options(NotifyBeforeAfter::transKeyArray('notify.notify_beforeafter_options'))
                ->default(NotifyBeforeAfter::BEFORE)
                ->required()
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_beforeafter") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more#'.exmtrans('common.help.task_schedule_id'))));
                
            $form->number('notify_hour', exmtrans("notify.notify_hour"))
                ->min(0)
                ->max(23)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_hour"));

            // get checkbox
            $form->checkbox('notify_saved_trigger', exmtrans("notify.header_trigger"))
                ->help(exmtrans("notify.help.notify_trigger"))
                ->options(NotifySavedType::transArray('common'))
                ->default(NotifySavedType::arrays())
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::CREATE_UPDATE_DATA]])])
                ;

            $form->text('notify_button_name', exmtrans("notify.notify_button_name"))
                ->required()
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::BUTTON]])])
                ->rules("max:40");
                    
            $form->switchbool('notify_myself', exmtrans("notify.notify_myself"))
            ->attribute([
                'data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::CREATE_UPDATE_DATA, NotifyTrigger::WORKFLOW]]),
            ])
            ->default(false)
            ->help(exmtrans("notify.help.notify_myself"));
        })->disableHeader();

        $form->exmheader(exmtrans("notify.header_action"))->hr();

        $form->hasManyJson('action_settings', exmtrans("notify.action_settings"), function ($form) {
            $form->select('notify_action', exmtrans("notify.notify_action"))
            ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
            ->required()
            ->attribute([
                'data-filtertrigger' =>true,
                'data-linkage' => json_encode([
                    'notify_action_target' => admin_url('notify/notify_action_target'),
                ]),
                'data-linkage-getdata' =>json_encode([
                    ['key' => 'custom_table_id', 'parent' => 1],
                    ['key' => 'workflow_id', 'parent' => 1],
                ]),
            ])
            ->config('allowClear', false)
            ->help(exmtrans("notify.help.notify_action"))
            ;

            $form->url('webhook_url', exmtrans("notify.webhook_url"))
                ->required()
                ->rules(["max:300"])
                ->help(exmtrans("notify.help.webhook_url", getManualUrl('notify_webhook')))
                ->attribute([
                    'data-filter' => json_encode(['key' => 'notify_action', 'value' => [NotifyAction::SLACK, NotifyAction::MICROSOFT_TEAMS]])
                ]);

            $form->switchbool('mention_here', exmtrans("notify.mention_here"))
                ->help(exmtrans("notify.help.mention_here"))
                ->attribute(['data-filter' => json_encode(['key' => 'notify_action', 'value' =>  [NotifyAction::SLACK]])
                ]);
            
            $system_slack_user_column = CustomColumn::getEloquent(System::system_slack_user_column());
            $notify_action_target_filter = isset($system_slack_user_column) ? [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE, NotifyAction::SLACK] : [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE];
            $form->multipleSelect('notify_action_target', exmtrans("notify.notify_action_target"))
                ->options(function ($val, $field, $notify) {
                    $options = [
                        'as_workflow' => !is_nullorempty($notify->workflow_id),
                    ];
                    return collect(NotifyService::getNotifyTargetColumns($notify->custom_table_id ?? null, array_get($field->data(), 'notify_action'), $options))
                        ->pluck('text', 'id');
                })
                ->attribute([
                    'data-filter' => json_encode([
                        ['key' => 'notify_action', 'value' => $notify_action_target_filter],
                        ['key' => 'notify_action', 'requiredValue' => [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE]],
                    ])
                ])
                ->help(exmtrans("notify.help.notify_action_target"));


            $form->textarea('target_emails', exmtrans("notify.target_emails"))
                ->required()
                ->rows(3)
                ->help(exmtrans("notify.help.target_emails"))
                ->rules([new EmailMultiline()])
                ->attribute([
                    'data-filter' => json_encode([
                        ['key' => 'notify_action', 'value' => [NotifyAction::EMAIL]],
                        ['key' => 'notify_action_target', 'value' => [NotifyActionTarget::FIXED_EMAIL]],
                    ])
                ]);


            if (!isset($system_slack_user_column)) {
                $form->display('notify_action_target_text', exmtrans("notify.notify_action_target"))
                    ->displayText(exmtrans('notify.help.slack_user_column_not_setting') . \Exment::getMoreTag('notify_webhook', 'notify.mention_setting_manual_id'))
                    ->attribute([
                        'data-filter' => json_encode([
                            ['key' => 'notify_action', 'value' => [NotifyAction::SLACK]]
                        ])
                    ])
                    ->escape(false);
                $form->ignore('notify_action_target_text');
            }
        })->required()->disableHeader();

        // get notify mail template
        $notify_mail_id = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::TIME_NOTIFY)->first()->id;

        $form->select('mail_template_id', exmtrans("notify.mail_template_id"))->options(function ($val) {
            return getModelName(SystemTableName::MAIL_TEMPLATE)::all()->pluck('label', 'id');
        })->help(exmtrans("notify.help.mail_template_id"))
        ->config('allowClear', false)
        ->default($notify_mail_id)->required();

        $form->tools(function (Form\Tools $tools) {
            $tools->append(new Tools\SystemChangePageMenu());
        });

        $form->saving(function (Form $form) {
            $error = false;
            if (is_null($form->action_settings)) {
                $error = true;
            } else {
                $cnt = collect($form->action_settings)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                })->count();
                if ($cnt == 0) {
                    $error = true;
                }
            }

            // if($error){
            //     admin_toastr(sprintf(exmtrans("common.message.exists_row"), exmtrans("notify.header_action")), 'error');
            //     return back()->withInput();
            // }
        });

        $form->disableEditingCheck(false);

        return $form;
    }

    public function targetcolumn(Request $request)
    {
        return $this->getTargetColumnOptions($request->get('q'), true);
    }

    public function notify_action_target(Request $request)
    {
        $options = NotifyService::getNotifyTargetColumns($request->get('custom_table_id'), $request->get('q'), [
            'as_workflow' => !is_nullorempty($request->get('workflow_id')),
        ]);

        return $options;
    }

    protected function getTargetColumnOptions($custom_table, $isApi)
    {
        $custom_table = CustomTable::getEloquent($custom_table);

        if (!isset($custom_table)) {
            return [];
        }

        $options = CustomColumn
            ::where('custom_table_id', $custom_table->id)
            ->whereIn('column_type', [ColumnType::DATE, ColumnType::DATETIME])
            ->get(['id', 'column_view_name as text']);

        if ($isApi) {
            return $options;
        } else {
            return $options->pluck('text', 'id');
        }
    }

    public function getNotifyTriggerTemplate(Request $request)
    {
        $keyName = 'mail_template_id';
        $value = $request->input('value');

        // get mail key enum
        $enum = NotifyTrigger::getEnum($value);
        if (!isset($enum)) {
            return [$keyName => null];
        }

        // get mailKeyName
        $mailKeyName = $enum->getDefaultMailKeyName();
        if (!isset($mailKeyName)) {
            return [$keyName => null];
        }

        // get mail template
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', $mailKeyName)
            ->first();
    
        if (!isset($mail_template)) {
            return [$keyName => null];
        }

        return [
            $keyName => $mail_template->id
        ];
    }

    /**
     * validate permission edit notify
     *
     * @param string|int|null $id
     * @return boolean
     */
    protected function hasPermissionEdit($id)
    {
        if (!isset($id)) {
            return true;
        }

        // filter only custom table user has permission custom table
        if (\Exment::user()->isAdministrator()) {
            return true;
        }

        $notify = Notify::find($id);

        $custom_tables = CustomTable::filterList()->pluck('id')->toArray();

        if (!in_array($notify->custom_table_id, $custom_tables)) {
            Checker::error();
            return false;
        }

        return true;
    }

    
    /**
     * Send data
     * @param Request $request
     */
    public function postNotifySetting(Request $request)
    {
        \DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, ['notify'], false, false);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            \DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('notify'));
        } catch (\Exception $exception) {
            //TODO:error handling
            \DB::rollback();
            throw $exception;
        }
    }
}

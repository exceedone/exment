<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Auth\Permission as Checker;
//use Encore\Admin\Controllers\HasResourceActions;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Validator\RequiredIfExRule;
use DB;

class NotifyController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
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
        $grid->column('custom_table_id', exmtrans("notify.custom_table_id"))->sortable()->display(function ($val) {
            return esc_html(CustomTable::getEloquent($val)->table_view_name);
        });
        $grid->column('notify_trigger', exmtrans("notify.notify_trigger"))->sortable()->display(function ($val) {
            return NotifyTrigger::getEnum($val)->transKey('notify.notify_trigger_options');
        });

        $grid->column('notify_actions', exmtrans("notify.notify_action"))->sortable()->display(function ($val) {
            return implode(exmtrans('common.separate_word'), collect($val)->map(function ($v) {
                $enum = NotifyAction::getEnum($v);
                return isset($enum) ? $enum->transKey('notify.notify_action_options') : null;
            })->toArray());
        });

        // filter only custom table user has permission custom table
        if (!\Exment::user()->isAdministrator()) {
            $custom_tables = CustomTable::filterList()->pluck('id')->toArray();
            $grid->model()->whereIn('custom_table_id', $custom_tables);
        }

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
            
            $linker = (new Linker)
                ->url(admin_urls("notify/create?copy_id={$actions->row->id}"))
                ->icon('fa-copy')
                ->tooltip(exmtrans('common.copy_item', exmtrans('notify.notify')));
            $actions->prepend($linker);
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
        $form->text('notify_view_name', exmtrans("notify.notify_view_name"))->required()->rules("max:40");
        // TODO: only role tables

        $form->exmheader(exmtrans('notify.header_trigger'))->hr();
        
        $form->select('notify_trigger', exmtrans("notify.notify_trigger"))
            ->options(NotifyTrigger::transKeyArray("notify.notify_trigger_options"))
            ->required()
            ->config('allowClear', false)
            ->attribute([
                'data-filtertrigger' =>true,
                'data-changedata' => json_encode([
                    'getitem' =>
                        [  'uri' => admin_url('notify/notifytrigger_template')
                        ]
                ])
            ])
            ->help(exmtrans("notify.help.notify_trigger"));

        $form->select('custom_table_id', exmtrans("notify.custom_table_id"))
        ->required()
        ->options(function ($custom_table_id) {
            return CustomTable::filterList()->pluck('table_view_name', 'id');
        })->attribute([
            'data-linkage' => json_encode([
                'trigger_settings_notify_target_column' =>  admin_url('notify/targetcolumn'),
                'action_settings_notify_action_target' => admin_url('notify/notify_action_target'),
                'custom_view_id' => [
                  'url' => admin_url('webapi/table/filterviews'),
                  'text' => 'view_view_name',
                ]
            ]),
            'data-filter' => json_encode(['key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME, NotifyTrigger::CREATE_UPDATE_DATA, NotifyTrigger::BUTTON]])
        ])
        ->help(exmtrans("notify.help.custom_table_id"));

        $form->select('workflow_id', exmtrans("notify.workflow_id"))
        ->required()
        ->options(function ($workflow_id) {
            return Workflow::all()->pluck('workflow_view_name', 'id');
        })->attribute([
            'data-filter' => json_encode(['key' => 'notify_trigger', 'value' => [NotifyTrigger::WORKFLOW]])
        ])
        ->help(exmtrans("notify.help.workflow_id"));

        $form->select('custom_view_id', exmtrans("notify.custom_view_id"))
            ->help(exmtrans("notify.help.custom_view_id"))
            ->options(function ($select_view, $form) {
                $data = $form->data();
                if (!isset($data)) {
                    return [];
                }

                // select_table
                if (is_null($select_target_table = array_get($data, 'custom_table_id'))) {
                    return [];
                }
                return CustomTable::getEloquent($select_target_table)->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') == ViewKindType::FILTER;
                    })->pluck('view_view_name', 'id');
            })->attribute([
                'data-filter' => json_encode(['key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME, NotifyTrigger::CREATE_UPDATE_DATA, NotifyTrigger::BUTTON]])
            ]);

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
                return $controller->getTargetColumnOptions(CustomColumn::getEloquent($val)->custom_table, false);
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
        })->disableHeader();

        $form->exmheader(exmtrans("notify.header_action"))->hr();
        $form->multipleSelect('notify_actions', exmtrans("notify.notify_action"))
            ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
            ->default([NotifyAction::SHOW_PAGE])
            ->required()
            ->attribute([
                'data-filtertrigger' =>true,
            ])
            ->config('allowClear', false)
            ->help(exmtrans("notify.help.notify_action"))
            ;

        $form->embeds('action_settings', exmtrans("notify.action_settings"), function (Form\EmbeddedForm $form) {
            $controller = $this;
            
            $form->text('webhook_url', exmtrans("notify.webhook_url"))
                ->rules(["max:300", new RequiredIfExRule(['notify_actions', '3'])])
                ->help(exmtrans("notify.help.webhook_url", getManualUrl('notify_webhook')))
                ->required()
                ->attribute([
                    'data-filter' => json_encode(['parent' => 1, 'key' => 'notify_actions', 'value' => [NotifyAction::SLACK, NotifyAction::MICROSOFT_TEAMS]])
                ]);

            $form->multipleSelect('notify_action_target', exmtrans("notify.notify_action_target"))
                ->options(function ($val) use ($controller) {
                    return $controller->getNotifyActionTargetOptions($this->custom_table_id ?? null, false);
                })
                ->default(NotifyActionTarget::HAS_ROLES)
                ->required()
                ->rules([new RequiredIfExRule(['notify_actions', '1', '2'])])
                ->attribute([
                    'data-filter' => json_encode([
                        ['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME, NotifyTrigger::CREATE_UPDATE_DATA, NotifyTrigger::BUTTON]],
                        ['parent' => 1, 'key' => 'notify_actions', 'value' => [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE]]
                    ])
                ])
                ->help(exmtrans("notify.help.notify_action_target"));

            // get notify mail template
            $notify_mail_id = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::TIME_NOTIFY)->first()->id;

            $form->select('mail_template_id', exmtrans("notify.mail_template_id"))->options(function ($val) {
                return getModelName(SystemTableName::MAIL_TEMPLATE)::all()->pluck('label', 'id');
            })->help(exmtrans("notify.help.mail_template_id"))
            ->config('allowClear', false)
            ->default($notify_mail_id)->required();
        })->disableHeader();
        
        return $form;
    }

    public function targetcolumn(Request $request)
    {
        return $this->getTargetColumnOptions($request->get('q'), true);
    }

    public function notify_action_target(Request $request)
    {
        return $this->getNotifyActionTargetOptions($request->get('q'), true);
    }

    protected function getTargetColumnOptions($custom_table, $isApi)
    {
        $custom_table = CustomTable::getEloquent($custom_table);

        $options = CustomColumn
            ::where('custom_table_id', $custom_table->id)
            ->whereIn('column_type', [ColumnType::DATE, ColumnType::DATETIME])
            ->get(['id', DB::raw('column_view_name as text')]);

        if ($isApi) {
            return $options;
        } else {
            return $options->pluck('text', 'id');
        }
    }

    protected function getNotifyActionTargetOptions($custom_table, $isApi)
    {
        $array = NotifyActionTarget::transArray('notify.notify_action_target_options');
        $options = [];
        foreach ($array as $k => $v) {
            $options[] = ['id' => $k, 'text' => $v];
        }

        if (isset($custom_table)) {
            $custom_table = CustomTable::getEloquent($custom_table);
            $options = array_merge($options, CustomColumn
            ::where('custom_table_id', $custom_table->id)
            ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION, ColumnType::EMAIL])
            ->get(
                ['id', DB::raw('column_view_name as text')]
            )->toArray());


            // get select table's
            $select_table_columns = $custom_table->custom_columns()
                ->where('column_type', ColumnType::SELECT_TABLE)
                ->get();

            foreach ($select_table_columns as $select_table_column) {
                if (is_null($select_target_table = $select_table_column->select_target_table)) {
                    continue;
                }

                // if has $emailColumn, add $select_table_column
                $emailColumn = CustomColumn
                    ::where('custom_table_id', $select_target_table->id)
                    ->where('column_type', ColumnType::EMAIL)
                    ->first();
                if (!isset($emailColumn)) {
                    continue;
                }

                $options[] = ['id' => $select_table_column->id, 'text' => $select_table_column->column_view_name];
            }
        }
        if ($isApi) {
            return $options;
        } else {
            return collect($options)->pluck('text', 'id')->toArray();
        }
    }
    
    public function getNotifyTriggerTemplate(Request $request)
    {
        $keyName = 'action_settings_mail_template_id';
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
     * @param [type] $id
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
}

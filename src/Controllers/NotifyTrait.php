<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Auth\Permission as Checker;
use Exceedone\Exment\Validator\EmailMultiline;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;

trait NotifyTrait
{
    /**
     * Make a grid builder.
     */
    // @phpstan-ignore-next-line
    protected function setBasicGrid($grid)
    {
        /** @phpstan-ignore-next-line */
        $grid->column('notify_view_name', exmtrans("notify.notify_view_name"))->sortable();
        /** @phpstan-ignore-next-line */
        $grid->column('notify_trigger', exmtrans("notify.notify_trigger"))->sortable()->display(function ($val) {
            $enum = NotifyTrigger::getEnum($val);
            return $enum ? $enum->transKey('notify.notify_trigger_options') : null;
        });
    }


    /**
     * Make a grid builder.
     */
    // @phpstan-ignore-next-line
    protected function setFilterGrid($grid, ?\Closure $callback = null)
    {
        $grid->disableExport();
        $grid->filter(function ($filter) use ($callback) {
            $filter->disableIdFilter();
            /** @phpstan-ignore-next-line */
            $filter->like('notify_name', exmtrans("notify.notify_name"));
            /** @phpstan-ignore-next-line */
            $filter->like('notify_view_name', exmtrans("notify.notify_view_name"));

            if ($callback) {
                $callback($filter);
            }

            /** @phpstan-ignore-next-line */
            $filter->equal('active_flg', exmtrans("plugin.active_flg"))->radio(\Exment::getYesNoAllOption());
        });
    }


    // @phpstan-ignore-next-line
    protected function setBasicForm(Form $form, ?Notify $notify)
    {
        /** @phpstan-ignore-next-line */
        if (!isset($notify) || is_nullorempty($notify->notify_name)) {
            /** @phpstan-ignore-next-line */
            $form->text('notify_name', exmtrans("notify.notify_name"))
                ->rules("max:30|nullable|unique:".Notify::getTableName()."|regex:/".Define::RULES_REGEX_SYSTEM_NAME."/")
                /** @phpstan-ignore-next-line */
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'));
        } else {
            /** @phpstan-ignore-next-line */
            $form->display('notify_name', exmtrans("notify.notify_name"));
        }

        /** @phpstan-ignore-next-line */
        $form->text('notify_view_name', exmtrans("notify.notify_view_name"))->required()->rules("max:40");
        // TODO: only role tables

        /** @phpstan-ignore-next-line */
        $form->switchbool('active_flg', exmtrans("plugin.active_flg"))
            /** @phpstan-ignore-next-line */
            ->help(exmtrans("notify.help.active_flg"))
            ->default(true);
    }


    // @phpstan-ignore-next-line
    protected function setActionForm($form, ?Notify $notify, $custom_table = null, $workflow = null, array $options = [])
    {
        if ($workflow) {
            $custom_table = $workflow->target_table;
        }

        /** @phpstan-ignore-next-line */
        $form->url('webhook_url', exmtrans("notify.webhook_url"))
            ->required()
            ->rules(["max:300"])
            /** @phpstan-ignore-next-line */
            ->help(exmtrans("notify.help.webhook_url", getManualUrl('notify_webhook')))
            ->attribute([
                'data-filter' => json_encode(['key' => 'notify_action', 'value' => [NotifyAction::SLACK, NotifyAction::MICROSOFT_TEAMS]])
            ]);

        /** @phpstan-ignore-next-line */
        $form->switchbool('mention_here', exmtrans("notify.mention_here"))
            /** @phpstan-ignore-next-line */
            ->help(exmtrans("notify.help.mention_here"))
            ->attribute(['data-filter' => json_encode(['key' => 'notify_action', 'value' =>  [NotifyAction::SLACK]])
            ]);

        $system_slack_user_column = CustomColumn::getEloquent(System::system_slack_user_column());
        $notify_action_target_filter = isset($system_slack_user_column) ? [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE, NotifyAction::SLACK] : [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE];

        /** @phpstan-ignore-next-line */
        $help = exmtrans("notify.help.notify_action_target");
        /** @phpstan-ignore-next-line */
        if (!is_nullorempty($workflow)) {
            /** @phpstan-ignore-next-line */
            $help .= exmtrans("notify.help.notify_action_target_add_workflow");
        }
        /** @phpstan-ignore-next-line */
        $form->multipleSelect('notify_action_target', exmtrans("notify.notify_action_target"))
            /** @phpstan-ignore-next-line */
            ->options(function ($val, $field, $notify) use ($custom_table, $workflow, $options) {
                $options = array_merge([
                    /** @phpstan-ignore-next-line */
                    'as_workflow' => !is_nullorempty($workflow),
                    'workflow' => $workflow,
                    'get_realtion_email' => true,
                ], $options);
                return collect(NotifyService::getNotifyTargetColumns(
                    $custom_table ?? null,
                    array_get($field->data(), 'notify_action'),
                    $options
                /** @phpstan-ignore-next-line */
                ))->pluck('text', 'id');
            })
            ->attribute([
                'data-filter' => json_encode([
                    ['key' => 'notify_action', 'value' => $notify_action_target_filter],
                    ['key' => 'notify_action', 'requiredValue' => [NotifyAction::EMAIL, NotifyAction::SHOW_PAGE]],
                ])
            ])
            ->help($help);

        /** @phpstan-ignore-next-line */
        $form->textarea('target_emails', exmtrans("notify.target_emails"))
            ->required()
            ->rows(3)
            /** @phpstan-ignore-next-line */
            ->help(exmtrans("notify.help.target_emails"))
            ->rules([new EmailMultiline()])
            ->attribute([
                'data-filter' => json_encode([
                    ['key' => 'notify_action', 'value' => [NotifyAction::EMAIL]],
                    ['key' => 'notify_action_target', 'value' => [NotifyActionTarget::FIXED_EMAIL]],
                ])
            ]);
        $selected_value = [];
        $form_index = $form->getIndex();
        if($form_index !== null ) {
            if(isset($notify->action_settings[$form_index]['target_users'])) {
                $selected_value = $notify->action_settings[$form_index]['target_users'];
            }
        }
        list($users, $ajax) = CustomTable::getEloquent(SystemTableName::USER)->getSelectOptionsAndAjaxUrl([
            'display_table' => $custom_table,
            'selected_value'=> $selected_value
        ]);

        /** @phpstan-ignore-next-line */
        $field = $form->multipleSelect('target_users', exmtrans('notify.target_users'))
            /** @phpstan-ignore-next-line */
            ->options($users)
            ->ajax($ajax)
            ->attribute([
                'data-filter' => json_encode([
                    ['key' => 'notify_action_target', 'value' => [NotifyActionTarget::FIXED_USER]],
                ])
            ]);

        if ($custom_table) {
            /** @phpstan-ignore-next-line */
            $field->help(exmtrans('workflow.help.target_user_org', [
                /** @phpstan-ignore-next-line */
                'table_view_name' => esc_html($custom_table->table_view_name),
                /** @phpstan-ignore-next-line */
                'type' => exmtrans('menu.system_definitions.user'),
            ]));
        }

        if (System::organization_available()) {
            if($form_index !== null ) {
                if(isset($notify->action_settings[$form_index]['target_organizations'])) {
                    $selected_value = $notify->action_settings[$form_index]['target_organizations'];
                }
            }
            list($organizations, $ajax) = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getSelectOptionsAndAjaxUrl([
                'display_table' => $custom_table,
                'selected_value'=> $selected_value
            ]);

            /** @phpstan-ignore-next-line */
            $field = $form->multipleSelect('target_organizations', exmtrans('notify.target_organizations'))
                /** @phpstan-ignore-next-line */
                ->options($organizations)
                ->ajax($ajax)
                ->attribute(['data-filter' => json_encode(['key' => 'notify_action_target', 'value' => [NotifyActionTarget::FIXED_ORGANIZATION]])])
            ;

            // Set help if has $custom_table
            if ($custom_table) {
                /** @phpstan-ignore-next-line */
                $field->help(exmtrans('workflow.help.target_user_org', [
                    /** @phpstan-ignore-next-line */
                    'table_view_name' => esc_html($custom_table->table_view_name),
                    /** @phpstan-ignore-next-line */
                    'type' => exmtrans('menu.system_definitions.organization'),
                ]));
            }
        }

        if (!isset($system_slack_user_column)) {
            /** @phpstan-ignore-next-line */
            $form->display('notify_action_target_text', exmtrans("notify.notify_action_target"))
                /** @phpstan-ignore-next-line */
                ->displayText(exmtrans('notify.help.slack_user_column_not_setting') . \Exment::getMoreTag('notify_webhook', 'notify.mention_setting_manual_id'))
                ->attribute([
                    'data-filter' => json_encode([
                        ['key' => 'notify_action', 'value' => [NotifyAction::SLACK]]
                    ])
                ])
                ->escape(false);
            $form->ignore('notify_action_target_text');
        }
    }

    // @phpstan-ignore-next-line
    protected function setMailTemplateForm($form, ?Notify $notify, $mail_template_id = null)
    {
        /** @phpstan-ignore-next-line */
        $form->select('mail_template_id', exmtrans("notify.mail_template_id"))->options(function ($val) {
            /** @phpstan-ignore-next-line */
            return getModelName(SystemTableName::MAIL_TEMPLATE)::all()->pluck('label', 'id');
        /** @phpstan-ignore-next-line */
        })->help(exmtrans("notify.help.mail_template_id"))
            ->disableClear()
            ->default($mail_template_id)
            ->requiredRule();
    }


    // @phpstan-ignore-next-line
    protected function setFooterForm(Form $form, ?Notify $notify)
    {
        $form->saving(function (Form $form) {
            $error = false;
            if (is_null($form->action_settings)) {
                $error = true;
            } else {
                $cnt = collect($form->action_settings)->filter(function ($value) {
                    return $value[Form::REMOVE_FLAG_NAME] != 1;
                /** @phpstan-ignore-next-line */
                })->count();
                if ($cnt == 0) {
                    $error = true;
                }
            }

            // if($error){
            /** @phpstan-ignore-next-line */
            //     admin_toastr(sprintf(exmtrans("common.message.exists_row"), exmtrans("notify.header_action")), 'error');
            //     return back()->withInput();
            // }
        });

        $form->disableEditingCheck(false);
    }


    // @phpstan-ignore-next-line
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
            /** @phpstan-ignore-next-line */
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
        /** @phpstan-ignore-next-line */
        if (\Exment::user()->isAdministrator()) {
            return true;
        }

        /** @phpstan-ignore-next-line */
        $notify = Notify::find($id);

        /** @phpstan-ignore-next-line */
        $custom_tables = CustomTable::filterList()->pluck('id')->toArray();

        if (!in_array($notify->target_id, $custom_tables)) {
            Checker::error();
            return false;
        }

        return true;
    }
}

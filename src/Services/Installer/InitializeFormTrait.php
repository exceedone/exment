<?php

namespace Exceedone\Exment\Services\Installer;

use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use ExmentAdminCore\Admin\Widgets\Box;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\TemplateImportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Validator;

/**
 *
 */
trait InitializeFormTrait
{
    /**
     *
     * @param string $routeName
     * @param boolean $isInitialize
     * @return WidgetForm
     */
    protected function getInitializeForm($routeName, $isInitialize = false): WidgetForm
    {
        $form = new WidgetForm(System::get_system_values(['initialize', 'system']));
        $form->disableReset();

        if ($isInitialize) {
            $form->exmheader(exmtrans('system.header'))->hr();
        } else {
            $form->progressTracker()->options($this->getProgressInfo(false));
        }

        $form->text('site_name', exmtrans("system.site_name"))
            ->required()
            ->help(exmtrans("system.help.site_name"));
        $form->text('site_name_short', exmtrans("system.site_name_short"))
            ->required()
            ->help(exmtrans("system.help.site_name_short"));

        $fileOption = array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => true,
                'deleteUrl' => admin_urls('system', 'filedelete'),
                'deleteExtraData'      => [
                    '_token'           => csrf_token(),
                    '_method'          => 'PUT',
                ]
            ]
        );
        array_set($fileOption, 'deleteExtraData.delete_flg', 'site_logo');
        $form->image('site_logo', exmtrans("system.site_logo"))
            ->help(exmtrans("system.help.site_logo"))
            ->options($fileOption)
            ->removable()
            ->attribute(['accept' => "image/*"])
        ;

        array_set($fileOption, 'deleteExtraData.delete_flg', 'site_logo_mini');
        $form->image('site_logo_mini', exmtrans("system.site_logo_mini"))
            ->help(exmtrans("system.help.site_logo_mini"))
            ->options($fileOption)
            ->removable()
            ->attribute(['accept' => "image/*"])
        ;
        array_set($fileOption, 'deleteExtraData.delete_flg', 'site_favicon');
        $form->favicon('site_favicon', exmtrans("system.site_favicon"))
            ->help(exmtrans("system.help.site_favicon"))
            ->options($fileOption)
            ->removable()
            ->attribute(['accept' => ".ico"])
        ;

        $form->select('site_skin', exmtrans("system.site_skin"))
            ->options(getTransArray(Define::SYSTEM_SKIN, "system.site_skin_options"))
            ->disableClear()
            ->help(exmtrans("system.help.site_skin"));
        $form->select('site_layout', exmtrans("system.site_layout"))
            ->options(getTransArray(array_keys(Define::SYSTEM_LAYOUT), "system.site_layout_options"))
            ->disableClear()
            ->help(exmtrans("system.help.site_layout"));

        // Screen design: on the system setting page only. The installer does
        // not ask - a new installation starts with the default design.
        // When the server fixes it (EXMENT_UI_DESIGN), a select would have no
        // effect, so the design in effect is shown as text instead and
        // nothing is posted.
        if (!$isInitialize) {
            $uiDesignForced = \Exment::getUiDesignForced();
            if (is_null($uiDesignForced)) {
                $form->select('ui_design', exmtrans("system.ui_design"))
                    ->options(getTransArray(Define::SYSTEM_UI_DESIGN, "system.ui_design_options"))
                    ->disableClear()
                    ->help(exmtrans("system.help.ui_design"));
            } else {
                $form->display('ui_design_forced', exmtrans("system.ui_design"))
                    ->displayText(exmtrans("system.ui_design_forced", exmtrans("system.ui_design_options.$uiDesignForced")))
                    ->help(exmtrans("system.help.ui_design"));
            }
        }

        $form->switchbool('api_available', exmtrans("system.api_available"))
            ->default(0)
            ->help(exmtrans("system.help.api_available", getManualUrl('system_setting?id=')));

        $form->switchbool('outside_api', exmtrans("system.outside_api"))
            ->default(!config('exment.outside_api') ? 1 : 0)
            ->help(exmtrans("system.help.outside_api"));

        $form->switchbool('permission_available', exmtrans("system.permission_available"))
            ->help(exmtrans("system.help.permission_available"));

        $form->switchbool('organization_available', exmtrans("system.organization_available"))
            ->help(exmtrans("system.help.organization_available"));

        $form->switchbool('logging_toggle_available', exmtrans("system.logging_toggle_available"))
            ->help(exmtrans("system.help.logging_toggle_available"));

        // which requests the system performance log saves (not on the install screen)
        if (!$isInitialize) {
            $form->number('logging_slow_request_seconds', exmtrans("system.logging_slow_request_seconds"))
                ->min(0)
                ->default(3)
                ->help(exmtrans("system.help.logging_slow_request_seconds"));
            $form->number('logging_slow_sql_seconds', exmtrans("system.logging_slow_sql_seconds"))
                ->min(0)
                ->default(1)
                ->help(exmtrans("system.help.logging_slow_sql_seconds"));
            $form->number('logging_max_per_hour', exmtrans("system.logging_max_per_hour"))
                ->min(0)
                ->default(100)
                ->help(exmtrans("system.help.logging_max_per_hour"));
        }

        // template list
        if ($isInitialize) {
            $this->addTemplateTile($form);
        }
        return $form;
    }


    /**
     * get sendmail test box.
     *
     * @return Box
     */
    protected function getsendmailTestBox(): Box
    {
        $form = new WidgetForm();
        $form->action(admin_urls('system/2factor'));
        $form->disableReset();
        $form->disableSubmit();

        $form->descriptionHtml(exmtrans('system.help.test_mail'));

        $form->email('test_mail_to', exmtrans("system.test_mail_to"));

        $form->ajaxButton('test_mail_send_button', exmtrans("system.submit_test_mail"))
            ->url(admin_urls('system', 'send_testmail'))
            ->button_class('btn-sm btn-info')
            ->attribute(['data-senddata' => json_encode(['test_mail_to'])])
            ->button_label(exmtrans('system.submit_test_mail'))
            ->send_params('test_mail_to');
        /** @phpstan-ignore-next-line constructor expects string, ExmentAdminCore\Admin\Widgets\Form given */
        return new Box(exmtrans("system.submit_test_mail"), $form);
    }


    // @phpstan-ignore-next-line
    protected function setNotifyForm($form)
    {
        // use mail setting
        $form->exmheader(exmtrans('system.system_mail'))->hr();
        if (!boolval(config('exment.mail_setting_env_force', false))) {
            $form->descriptionHtml(exmtrans("system.help.system_mail"));

            $form->text('system_mail_host', exmtrans("system.system_mail_host"));

            $form->text('system_mail_port', exmtrans("system.system_mail_port"));

            $form->text('system_mail_encryption', exmtrans("system.system_mail_encryption"))
                ->help(exmtrans("system.help.system_mail_encryption"));

            $form->text('system_mail_username', exmtrans("system.system_mail_username"));

            $form->password('system_mail_password', exmtrans("system.system_mail_password"));

            $form->email('system_mail_from', exmtrans("system.system_mail_from"))
                ->help(exmtrans("system.help.system_mail_from"));

            $form->text('system_mail_from_view_name', exmtrans("system.system_mail_from_view_name"))
                ->help(exmtrans("system.help.system_mail_from_view_name"));
        }
        $form->select('system_mail_body_type', exmtrans("system.system_mail_body_type"))
            ->help(exmtrans("system.help.system_mail_body_type"))
            ->disableClear()
            ->options(Enums\MailBodyType::transArray('system.system_mail_body_type_options'));

        $form->exmheader(exmtrans('system.system_slack'))->hr();

        $form->select('system_slack_user_column', exmtrans('system.system_slack_user_column'))
            ->help(exmtrans('system.help.system_slack_user_column'))
            ->options($this->getUserOrgSlackColumns('user'));

        $form->exmheader(exmtrans('system.system_line'))->hr();

        $form->password('system_line_channel_token', exmtrans('system.system_line_channel_token'));

        $form->password('system_line_channel_secret', exmtrans('system.system_line_channel_secret'));

        $form->text('system_line_oa_basic_id', exmtrans('system.system_line_oa_basic_id'))
            ->help(exmtrans('system.help.system_line_oa_basic_id'));

        $form->display('system_line_webhook_url', exmtrans('system.system_line_webhook_url'))
            ->default(admin_url('line/webhook'));

        // the Teams workflow (ISS02863): the address is a secret. It is not put back on the page:
        // a new one replaces it, an empty field keeps it
        $form->exmheader(exmtrans('system.system_teams'))->hr();
        $form->display('system_teams_workflow_state', exmtrans('system.system_teams_workflow_url'))
            ->default(is_nullorempty(System::system_teams_workflow_url()) ? exmtrans('system.system_teams_workflow_unset') : exmtrans('system.system_teams_workflow_set'));
        $form->password('system_teams_workflow_url_new', exmtrans('system.system_teams_workflow_url_new'))
            ->default('')
            ->help(exmtrans('system.system_teams_workflow_url_help', ['manual' => getManualUrl('notify_teams')]));
        $form->switchbool('system_teams_workflow_url_clear', exmtrans('system.system_teams_workflow_url_clear'))
            ->default(false);

        // the LINE WORKS app (ISS00114), while its feature is used: the secret and the private key
        // are not put back on the page (a new one replaces it, an empty field keeps it)
        if (\Exceedone\Exment\Services\Features::enabled(\Exceedone\Exment\Services\Features::LINE_WORKS_NOTIFY)) {
            $form->exmheader(exmtrans('system.system_lineworks'))->hr();
            $form->descriptionHtml(exmtrans('system.system_lineworks_help', ['manual' => getManualUrl('notify_lineworks')]));
            $form->text('system_lineworks_client_id', exmtrans('system.system_lineworks_client_id'));
            foreach (['system_lineworks_client_secret', 'system_lineworks_private_key'] as $secret) {
                $form->display($secret . '_state', exmtrans('system.' . $secret))
                    ->default(is_nullorempty(System::{$secret}()) ? exmtrans('system.system_teams_workflow_unset') : exmtrans('system.system_teams_workflow_set'));
                if ($secret === 'system_lineworks_private_key') {
                    $form->textarea($secret . '_new', exmtrans('system.system_lineworks_new', ['name' => exmtrans('system.' . $secret)]))->rows(4)->default('');
                } else {
                    $form->password($secret . '_new', exmtrans('system.system_lineworks_new', ['name' => exmtrans('system.' . $secret)]))->default('');
                }
                $form->switchbool($secret . '_clear', exmtrans('system.system_lineworks_clear', ['name' => exmtrans('system.' . $secret)]))->default(false);
            }
            $form->text('system_lineworks_service_account', exmtrans('system.system_lineworks_service_account'));
            $form->text('system_lineworks_bot_id', exmtrans('system.system_lineworks_bot_id'));
            $form->select('system_lineworks_user_column', exmtrans('system.system_lineworks_user_column'))
                ->help(exmtrans('system.help.system_lineworks_user_column'))
                ->options($this->getUserOrgSlackColumns('user'));
        }

        // the safety check has a page of its own (Controllers\SafetyCheckSettingController, #2157)
    }


    // @phpstan-ignore-next-line
    protected function postInitializeForm(Request $request, $group = null, $initialize = false, $validateUser = false)
    {
        $rules = [
            'site_name' => 'max:30',
            'site_name_short' => 'max:10',
        ];
        if ($initialize) {
            $rules = array_merge($rules, [
                'user_code' => 'required|max:32|regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/',
                'user_name' => 'required|max:32',
                'email' => 'required|email',
                'password' => get_password_rule(true, null),
            ]);
        } elseif ($validateUser) {
            $rules = array_merge($rules, [
                'system_admin_users' => 'required',
            ]);
        }

        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput(get_flash_input())->withErrors($validation);
        }

        $inputs = $request->all(System::get_system_keys($group));
        array_forget($inputs, 'initialized');
        // Screen design: save only one of the known designs. The installer
        // form, and the system setting page when the server fixes the design,
        // have no select; a missing or unknown value must not replace the
        // stored one.
        if (array_key_exists('ui_design', $inputs) && !in_array($inputs['ui_design'], Define::SYSTEM_UI_DESIGN, true)) {
            array_forget($inputs, 'ui_design');
        }

        // the address of the Teams workflow is never on the page: a new one, or cleared, or kept
        if (array_key_exists('system_teams_workflow_url', $inputs) || $request->has('system_teams_workflow_url_new')) {
            array_forget($inputs, 'system_teams_workflow_url');
            $newUrl = trim(strval($request->input('system_teams_workflow_url_new')));
            if ($newUrl !== '') {
                if (!preg_match('#\Ahttps://#i', $newUrl) || !\Exceedone\Exment\Services\WebhookUrlGuard::isAllowed($newUrl)) {
                    return back()->withInput(array_except(get_flash_input(), ['system_teams_workflow_url_new']))->withErrors(['system_teams_workflow_url_new' => exmtrans('notify.message.webhook_url_not_allowed')]);
                }
                $inputs['system_teams_workflow_url'] = $newUrl;
            } elseif (boolval($request->input('system_teams_workflow_url_clear'))) {
                $inputs['system_teams_workflow_url'] = null;
            }
        }

        // the secrets of the LINE WORKS app are never on the page: a new one, or cleared, or kept
        foreach (['system_lineworks_client_secret', 'system_lineworks_private_key'] as $secret) {
            if (array_key_exists($secret, $inputs) || $request->has($secret . '_new')) {
                array_forget($inputs, $secret);
                $new = trim(strval($request->input($secret . '_new')));
                if ($new !== '') {
                    if ($secret === 'system_lineworks_private_key' && openssl_pkey_get_private($new) === false) {
                        return back()->withInput(array_except(get_flash_input(), [$secret . '_new']))->withErrors([$secret . '_new' => exmtrans('system.system_lineworks_private_key_invalid')]);
                    }
                    $inputs[$secret] = $new;
                } elseif (boolval($request->input($secret . '_clear'))) {
                    $inputs[$secret] = null;
                }
            }
        }

        // demo mode: the mail, LINE, search server, IP filter, reCAPTCHA and API settings are kept
        $inputs = DemoMode::filterSystemInputs($inputs);

        $loggingWasOn = boolval(System::logging_toggle_available());

        // set system_key and value
        foreach ($inputs as $k => $input) {
            System::{$k}($input);
        }
        // the log is on: make the table "System Performance Log" if it is not there yet,
        // and its menu too when the log has just been turned on
        TemplateImportExport\TemplateImporter::importSystemLogsTemplateIfEnabled(!$loggingWasOn);
        // demo mode: no template upload or import
        if (DemoMode::enabled()) {
            return true;
        }
        // upload zip file
        $this->uploadTemplate($request);
        // import template
        if ($request->has('template')) {
            $importer = new TemplateImportExport\TemplateImporter();
            $importer->importTemplate($request->input('template'));
        }
        return true;
    }

    // @phpstan-ignore-next-line
    protected function addTemplateTile($form)
    {
        $form->exmheader(exmtrans('template.header'))->hr();
        // template list
        $form->tile('template', exmtrans("system.template"))
            ->help(exmtrans("system.help.template"))
            ->overlay()
        ;
        $form->file('upload_template', exmtrans('template.upload_template'))
            ->rules('mimes:zip,xlsx|nullable')
            ->attribute(['accept' => ".zip,.xlsx"])
            ->help(exmtrans('template.help.upload_template'))
            ->removable()
            ->options(Define::FILE_OPTION());

        // $form->file('upload_template_excel', exmtrans('template.upload_template_excel'))
        //     ->rules('mimes:xlsx|nullable')
        //     ->attribute(['accept' => ".xlsx"])
        //     ->help(exmtrans('template.help.upload_template_excel'))
        //     ->options(Define::FILE_OPTION());

        // template search url
        $template_search_url = System::initialized()
            ? admin_urls('webapi', 'template', 'search')
            : admin_urls('api', 'template', 'search');
        $script = <<<EOT

    $(function(){
        searchTemplate(null);
    });

    function searchTemplate(q, url){
        if(!hasValue(url)){
            url = '$template_search_url';
        }
        $('#tile-template .overlay').show();
        $.ajax({
            method: 'POST',
            url: url,
            data: {
                q: q,
                name: 'template',
                column: 'template',
                _token:LA.token,
            },
            success: function (data) {
                $('#tile-template .tile-group-items').html(data);
                $('#tile-template .overlay').hide();
            }
        });
    }
EOT;
        \Admin::script($script);
    }
    /**
     * Upload Template
     */
    // @phpstan-ignore-next-line
    protected function uploadTemplate(Request $request)
    {
        // upload zip file
        $upload_template = null;
        $importer = new TemplateImportExport\TemplateImporter();
        if ($request->has('upload_template')) {
            // get upload file
            $file = $request->file('upload_template');

            // upload excel file
            // @phpstan-ignore-next-line
            if ($file->getClientOriginalExtension() == 'xlsx') {
                $json = $importer->uploadTemplateExcel($file);
                $importer->import($json, false, false, true);
            }
            // upload zip file
            // @phpstan-ignore-next-line
            elseif ($file->getClientOriginalExtension() == 'zip') {
                $importer->uploadTemplate($file);
            }
        }
    }

    /**
     * file delete system.
     */
    // @phpstan-ignore-next-line
    public function filedelete(Request $request)
    {
        // get file delete flg column name
        $del_column_name = $request->input('delete_flg');
        System::deleteValue($del_column_name);
        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }

    // @phpstan-ignore-next-line
    protected function guard()
    {
        return Auth::guard('admin');
    }


    // @phpstan-ignore-next-line
    protected function getUserOrgSlackColumns(string $table_name)
    {
        return $this->getUserOrgColumns($table_name, Enums\ColumnType::TEXT);
    }

    // @phpstan-ignore-next-line
    protected function getUserOrgColumns(string $table_name, string $column_type)
    {
        $custom_table = CustomTable::getEloquent($table_name);
        if (!$custom_table) {
            return [];
        }

        return $custom_table->custom_columns_cache
            ->filter(function ($custom_column) use ($column_type) {
                return isMatchString($custom_column->column_type, $column_type);
            })->pluck('column_view_name', 'id');
    }


    /**
     * Get progress info
     *
     * @param bool $isAdvanced
     * @return array
     */
    // @phpstan-ignore-next-line
    protected function getProgressInfo(bool $isAdvanced): array
    {
        $steps = [];

        $steps[] = [
            'active' => !$isAdvanced,
            'complete' => false,
            'url' => admin_urls('system'),
            'description' => exmtrans('common.basic_setting'),
        ];

        $steps[] = [
            'active' => $isAdvanced,
            'complete' => false,
            'url' => admin_urls_query('system', ['advanced' => 1]),
            'description' => exmtrans('common.detail_setting'),
        ];

        return $steps;
    }
}

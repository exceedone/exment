<?php

namespace Exceedone\Exment\Services\Installer;

use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Widgets\Box;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
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

        $form->switchbool('api_available', exmtrans("system.api_available"))
            ->default(0)
            ->help(exmtrans("system.help.api_available"));

        $form->switchbool('outside_api', exmtrans("system.outside_api"))
            ->default(!config('exment.outside_api') ? 1 : 0)
            ->help(exmtrans("system.help.outside_api"));

        $form->switchbool('permission_available', exmtrans("system.permission_available"))
            ->help(exmtrans("system.help.permission_available"));

        $form->switchbool('organization_available', exmtrans("system.organization_available"))
            ->help(exmtrans("system.help.organization_available"));

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

        return new Box(exmtrans("system.submit_test_mail"), $form);
    }


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
    }


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
            return back()->withInput()->withErrors($validation);
        }

        $inputs = $request->all(System::get_system_keys($group));
        array_forget($inputs, 'initialized');

        // set system_key and value
        foreach ($inputs as $k => $input) {
            System::{$k}($input);
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
        $template_search_url = admin_urls('api', 'template', 'search');
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
    protected function uploadTemplate(Request $request)
    {
        // upload zip file
        $upload_template = null;
        $importer = new TemplateImportExport\TemplateImporter();
        if ($request->has('upload_template')) {
            // get upload file
            $file = $request->file('upload_template');

            // upload excel file
            if ($file->getClientOriginalExtension() == 'xlsx') {
                $json = $importer->uploadTemplateExcel($file);
                $importer->import($json, false, false, true);
            }
            // upload zip file
            elseif ($file->getClientOriginalExtension() == 'zip') {
                $upload_template = $importer->uploadTemplate($file);
                $importer->importTemplate($upload_template);
            }
        }
    }

    /**
     * file delete system.
     */
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

    protected function guard()
    {
        return Auth::guard('admin');
    }


    protected function getUserOrgSlackColumns(string $table_name)
    {
        return $this->getUserOrgColumns($table_name, Enums\ColumnType::TEXT);
    }

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

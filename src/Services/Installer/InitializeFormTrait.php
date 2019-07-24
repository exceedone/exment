<?php
namespace Exceedone\Exment\Services\Installer;

use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\TemplateImportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Validator;

/**
 *
 */
trait InitializeFormTrait
{
    /**
     *
     * @param [type] $routeName
     * @param boolean $add_template
     * @return void
     */
    protected function getInitializeForm($routeName, $add_template = false, $system_page = false)
    {
        $form = new WidgetForm(System::get_system_values(['initialize', 'system']));
        $form->disableReset();
        
        $form->exmheader(exmtrans('system.header'))->hr();
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
        $form->image('site_favicon', exmtrans("system.site_favicon"))
            ->help(exmtrans("system.help.site_favicon"))
            ->options($fileOption)
            ->removable()
            ->attribute(['accept' => ".ico"])
            ;
    
        $form->select('site_skin', exmtrans("system.site_skin"))
            ->options(getTransArray(Define::SYSTEM_SKIN, "system.site_skin_options"))
            ->config('allowClear', false)
            ->help(exmtrans("system.help.site_skin"));
        $form->select('site_layout', exmtrans("system.site_layout"))
            ->options(getTransArray(array_keys(Define::SYSTEM_LAYOUT), "system.site_layout_options"))
            ->config('allowClear', false)
            ->help(exmtrans("system.help.site_layout"));
        
        if ($system_page) {
            $form->select('grid_pager_count', exmtrans("system.grid_pager_count"))
            ->options(getPagerOptions())
            ->config('allowClear', false)
            ->default(20)
            ->help(exmtrans("system.help.grid_pager_count"));
            
            $form->select('datalist_pager_count', exmtrans("system.datalist_pager_count"))
            ->options(getPagerOptions(false, Define::PAGER_DATALIST_COUNTS))
            ->config('allowClear', false)
            ->default(5)
            ->help(exmtrans("system.help.datalist_pager_count"));
        }
        
        $form->switchbool('outside_api', exmtrans("system.outside_api"))
            ->default(!config('exment.outside_api') ? 1 : 0)
            ->help(exmtrans("system.help.outside_api"));
    
        $form->switchbool('permission_available', exmtrans("system.permission_available"))
            ->help(exmtrans("system.help.permission_available"));
        $form->switchbool('organization_available', exmtrans("system.organization_available"))
            ->help(exmtrans("system.help.organization_available"));
        
        if ($system_page) {
            $form->display('max_file_size', exmtrans("common.max_file_size"))
            ->default(Define::FILE_OPTION()['maxFileSizeHuman'])
            ->help(exmtrans("common.help.max_file_size", getManualUrl('quickstart_more#' . exmtrans('common.help.max_file_size_link'))));
            
            $form->multipleSelect('system_admin_users', exmtrans('system.system_admin_users'))
                ->help(exmtrans('system.help.system_admin_users'))
                ->required()
                ->options(function ($option) {
                    return CustomTable::getEloquent(SystemTableName::USER)->getSelectOptions([
                        'selected_value' => $option,
                    ]);
                })->default(System::system_admin_users());

            // use mail setting
            if (!boolval(config('exment.mail_setting_env_force', false))) {
                $form->exmheader(exmtrans('system.system_mail'))->hr();

                $form->description(exmtrans("system.help.system_mail"));
    
                $form->text('system_mail_host', exmtrans("system.system_mail_host"));
    
                $form->text('system_mail_port', exmtrans("system.system_mail_port"));
    
                $form->text('system_mail_encryption', exmtrans("system.system_mail_encryption"))
                    ->help(exmtrans("system.help.system_mail_encryption"));
                    
                $form->text('system_mail_username', exmtrans("system.system_mail_username"));
    
                $form->password('system_mail_password', exmtrans("system.system_mail_password"));
            }
        }
        $form->email('system_mail_from', exmtrans("system.system_mail_from"))
            ->help(exmtrans("system.help.system_mail_from"));

        // template list
        if ($add_template) {
            $this->addTemplateTile($form);
        }
        return $form;
    }
    
    protected function postInitializeForm(Request $request, $group = null, $initialize = false)
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
                'password' => get_password_rule(true),
            ]);
        } else {
            $rules = array_merge($rules, [
                'system_admin_users' => 'required',
            ]);
        }

        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }
        
        // check role user-or-org at least 1 data
        // if (!$initialize && System::permission_available()) {
        //     $roles = collect($request->all())->filter(function ($value, $key) {
        //         if (strpos($key, "role_") !== 0) {
        //             return false;
        //         }

        //         if (!collect($value)->filter(function ($v) {
        //             return isset($v);
        //         })->first()) {
        //             return false;
        //         }

        //         return true;
        //     });

        //     // if empty, return error
        //     if (count($roles) == 0) {
        //         admin_error(exmtrans('common.error'), exmtrans('system.help.role_one_user_organization'));
        //         return back()->withInput();
        //     }
        // }

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
            TemplateImportExport\TemplateImporter::importTemplate($request->input('template'));
        }
        return true;
    }
    
    /**
     * get system template list
     */
    protected function getTemplates()
    {
        $templates_path = app_path("Templates");
        $paths = File::glob("$templates_path/*/config.json");
        $templates = [];
        foreach ($paths as $path) {
            try {
                $json = json_decode(File::get($path));
                array_push($templates, $json);
            } catch (Exception $exception) {
                //TODO:error handling
            }
        }
        return collect($templates);
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
            ->rules('mimes:zip|nullable')
            ->help(exmtrans('template.help.upload_template'))
            ->removable()
            ->options(Define::FILE_OPTION());
        // $form->file('upload_template_excel', exmtrans('template.upload_template_excel'))
        //     ->rules('mimes:xlsx|nullable')
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
        if ($request->has('upload_template')) {
            // get upload file
            $file = $request->file('upload_template');
            $upload_template = TemplateImportExport\TemplateImporter::uploadTemplate($file);
            TemplateImportExport\TemplateImporter::importTemplate($upload_template);
        }
        
        // upload excel file
        if ($request->has('upload_template_excel')) {
            // get upload file
            $file = $request->file('upload_template_excel');
            $json = TemplateImportExport\TemplateImporter::uploadTemplateExcel($file);
            TemplateImportExport\TemplateImporter::import($json);
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
}

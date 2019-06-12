<?php
namespace Exceedone\Exment\Services\Installer;

use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TemplateImportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $form = new WidgetForm(System::get_system_values());
        $form->disableReset();
        
        $form->header(exmtrans('system.header'))->hr();
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
            ->attribute(['accept' => "image/*"])
            ;
            
        array_set($fileOption, 'deleteExtraData.delete_flg', 'site_logo_mini');
        $form->image('site_logo_mini', exmtrans("system.site_logo_mini"))
            ->help(exmtrans("system.help.site_logo_mini"))
            ->options($fileOption)
            ->attribute(['accept' => "image/*"])
            ;
        array_set($fileOption, 'deleteExtraData.delete_flg', 'site_favicon');
        $form->image('site_favicon', exmtrans("system.site_favicon"))
            ->help(exmtrans("system.help.site_favicon"))
            ->options($fileOption)
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
        
        $form->select('grid_pager_count', exmtrans("system.grid_pager_count"))
            ->options(getPagerOptions())
            ->config('allowClear', false)
            ->help(exmtrans("system.help.grid_pager_count"));
            
        $form->switchbool('outside_api', exmtrans("system.outside_api"))
            ->default(!config('exment.outside_api') ? 1 : 0)
            ->help(exmtrans("system.help.outside_api"));
    
        $form->switchbool('permission_available', exmtrans("system.permission_available"))
            ->help(exmtrans("system.help.permission_available"));
        $form->switchbool('organization_available', exmtrans("system.organization_available"))
            ->help(exmtrans("system.help.organization_available"));
        $form->email('system_mail_from', exmtrans("system.system_mail_from"))
            ->required()
            ->help(exmtrans("system.help.system_mail_from"));
            
        if ($system_page) {
            $form->display('max_file_size', exmtrans("common.max_file_size"))
            ->default(Define::FILE_OPTION()['maxFileSizeHuman'])
            ->help(exmtrans("common.help.max_file_size", getManualUrl('quickstart_more#' . exmtrans('common.help.max_file_size_link'))));
        }

        // template list
        if ($add_template) {
            $this->addTemplateTile($form);
        }
        return $form;
    }
    
    protected function postInitializeForm(Request $request, $validateUser = false)
    {
        $rules = [
            'site_name' => 'max:30',
            'site_name_short' => 'max:10',
        ];
        if ($validateUser) {
            $rules = array_merge($rules, [
                'user_code' => 'required|max:32|regex:/'.Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN.'/',
                'user_name' => 'required|max:32',
                'email' => 'required|email',
                'password' => get_password_rule(true),
            ]);
        }
        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return back()->withInput()->withErrors($validation);
        }
        $inputs = $request->all(System::get_system_keys('initialize'));
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
        $form->header(exmtrans('template.header'))->hr();
        // template list
        $form->tile('template', exmtrans("system.template"))
            ->help(exmtrans("system.help.template"))
            ;
        $form->file('upload_template', exmtrans('template.upload_template'))
            ->rules('mimes:zip|nullable')
            ->help(exmtrans('template.help.upload_template'))
            ->options(Define::FILE_OPTION());
        $form->file('upload_template_excel', exmtrans('template.upload_template_excel'))
            ->rules('mimes:xlsx|nullable')
            ->help(exmtrans('template.help.upload_template_excel'))
            ->options(Define::FILE_OPTION());
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
    
    protected function guard()
    {
        return Auth::guard('admin');
    }
}

<?php
namespace Exceedone\Exment\Services\Installer;

use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
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
     * @return WidgetForm
     */
    protected function getInitializeForm($routeName, $add_template = false)
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
        if ($add_template) {
            $this->addTemplateTile($form);
        }
        return $form;
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
            $importer = new TemplateImportExport\TemplateImporter;
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
            ->rules('mimes:zip|nullable')
            ->attribute(['accept' => ".zip"])
            ->help(exmtrans('template.help.upload_template'))
            ->removable()
            ->options(Define::FILE_OPTION());

        $form->file('upload_template_excel', exmtrans('template.upload_template_excel'))
            ->rules('mimes:xlsx|nullable')
            ->attribute(['accept' => ".xlsx"])
            ->help(exmtrans('template.help.upload_template_excel'))
            ->options(Define::FILE_OPTION());

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
        $importer = new TemplateImportExport\TemplateImporter;
        if ($request->has('upload_template')) {
            // get upload file
            $file = $request->file('upload_template');
            $upload_template = $importer->uploadTemplate($file);
            $importer->importTemplate($upload_template);
        }
        
        // upload excel file
        if ($request->has('upload_template_excel')) {
            // get upload file
            $file = $request->file('upload_template_excel');
            $json = $importer->uploadTemplateExcel($file);
            $importer->import($json, false, false, true);
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

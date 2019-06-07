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
class InitializeForm
{
    use InstallFormTrait;

    public function index(){
        $form = $this->getInitializeForm('initialize', true);
        $form->action(admin_url('initialize'));
        $form->disablePjax();

        // ID and password --------------------------------------------------
        $form->header(exmtrans('system.administrator'))->hr();
        $form->text('user_code', exmtrans('user.user_code'))->required()->help(exmtrans('common.help_code'));
        $form->text('user_name', exmtrans('user.user_name'))->required()->help(exmtrans('user.help.user_name'));
        $form->text('email', exmtrans('user.email'))->required()->help(exmtrans('user.help.email'));
        $form->password('password', exmtrans('user.password'))->required()->help(exmtrans('user.help.password'));
        $form->password('password_confirmation', exmtrans('user.password_confirmation'))->required();

        return view('exment::initialize.content', [
            'content'=> $form->render(),
            'header' => exmtrans('system.initialize_header'),
            'description' => exmtrans('system.initialize_description'),
        ]);
    }
    
    public function post(){
        $request = request();
        \DB::beginTransaction();
        
        try {
            $result = $this->postInitializeForm($request, true);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }
            
            // add user table
            $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
            $user->value = [
                'user_code' => $request->get('user_code'),
                'user_name' => $request->get('user_name'),
                'email' => $request->get('email'),
            ];
            $user->saveOrFail();

            // add login_user table
            $loginuser = new LoginUser;
            $loginuser->base_user_id = $user->id;
            $loginuser->password = $request->get('password');
            $loginuser->saveOrFail();

            // add system role
            \DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                [
                    'related_id' => $user->id,
                    'related_type' => SystemTableName::USER,
                    'morph_id' => null,
                    'morph_type' =>  RoleType::SYSTEM()->lowerKey(),
                    'role_id' => Role::where('role_type', RoleType::SYSTEM)->first()->id,
                ]
            );

            // add system initialized flg.
            System::initialized(1);
            \DB::commit();

            admin_toastr(trans('admin.save_succeeded'));
            $this->guard()->login($loginuser);
            return redirect(admin_url('/'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }
    }

    /**
     * TODO refactor!!!
     *
     * @param [type] $routeName
     * @param boolean $add_template
     * @return void
     */
    protected function getInitializeForm($routeName, $add_template = false)
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

        $form->switchbool('permission_available', exmtrans("system.permission_available"))
            ->help(exmtrans("system.help.permission_available"));

        $form->switchbool('organization_available', exmtrans("system.organization_available"))
            ->help(exmtrans("system.help.organization_available"));

        $form->email('system_mail_from', exmtrans("system.system_mail_from"))
            ->required()
            ->help(exmtrans("system.help.system_mail_from"));

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

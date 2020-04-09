<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\ApiClientRepository;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Laravel\Passport\Client;
use Encore\Admin\Layout\Content;

class LoginSettingController extends AdminControllerBase
{
    use InitializeFormTrait;
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("login.header"), exmtrans("login.header"), exmtrans("login.description"), 'fa-sign-in');
    }
    
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $content = $this->AdminContent($content);

        $content->row($this->grid());
        $content->row($this->globalSettingBox($request));
        return $content;
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new LoginSetting);
        $grid->column('login_type', exmtrans('login.login_type'));
        $grid->column('name', exmtrans('login.login_setting_name'));

        $grid->disableFilter();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new LoginSetting);
        $login_setting = LoginSetting::find($id);

        $errors = $this->checkOauthLibraries();

        $form->description(exmtrans('common.help.more_help'));

        $form->text('name', exmtrans('login.login_setting_name'))->required();

        if (!isset($id)) {
            $form->radio('login_type', exmtrans('login.login_type'))->options(LoginType::transArray('login.login_type_options'))
            ->required()
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans('common.help.init_flg'));
        } else {
            $form->display('login_type_text', exmtrans('login.login_type'));
            $form->hidden('login_type');
        }
        
        $form->embeds('options', exmtrans("login.options"), function (Form\EmbeddedForm $form) use ($login_setting, $errors) {
            ///// toggle 
            // if create or oauth
            if (!isset($login_setting) || $login_setting->login_type == LoginType::OAUTH) {
                $this->setOAuthForm($form, $errors);
            }
        })->disableHeader();

        $form->disableReset();
        return $form;
    }

    protected function setOAuthForm($form, $errors){
        if(array_has($errors, LoginType::OAUTH)){
            $form->description($errors[LoginType::OAUTH]);

            return;
        }

        $form->select('login_provider_type', exmtrans('login.login_provider_type'))
        ->options(LoginProviderType::transKeyArray('login.login_provider_type_options'))
        ->required()
        ->attribute(['data-filtertrigger' => true, 'data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('login_provider_name', exmtrans('login.login_provider_name'))
        ->required()
        ->help(exmtrans('login.help.login_provider_name'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->text('client_id', exmtrans('login.client_id'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('client_secret', exmtrans('login.client_secret'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('scope', exmtrans('login.scope'))
        ->help(exmtrans('login.help.scope'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        if (boolval(config('exment.expart_mode', false))) {
            $form->url('redirect_url', exmtrans('login.redirect_url'))
            ->help(exmtrans('login.help.redirect_url'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);
        }

        $form->text('login_button_label', exmtrans('login.login_button_label'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_label'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);
        
        $form->icon('login_button_icon', exmtrans('login.login_button_icon'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_icon'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->color('login_button_background_color', exmtrans('login.login_button_background_color'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_background_color'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->color('login_button_background_color_hover', exmtrans('login.login_button_background_color_hover'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_background_color_hover'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->color('login_button_font_color', exmtrans('login.login_button_font_color'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_font_color'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->color('login_button_font_color_hover', exmtrans('login.login_button_font_color_hover'))
        ->default(null)
        ->help(exmtrans('login.help.login_button_font_color_hover'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_login_provider_type', 'value' => [LoginProviderType::OTHER]])]);
    }

    /**
     * Checking OAuth library
     *
     * @return void
     */
    protected function checkOauthLibraries(){
        $errors = [];
        if(!class_exists('\\Laravel\\Socialite\\SocialiteServiceProvider')){
            $errors[] = LoginType::OAUTH();
        }

        return collect($errors)->mapWithKeys(function($error){
            return [$error->getValue() => '<span class="red">' . exmtrans('login.message.not_install_library', [
                'name' => $error->transKey('login.login_type_options'),
                'url' => getManualUrl('sso'),
            ]) . '</span>'];
        });
    }
    
    /**
     * Send data for global setting
     * @param Request $request
     */
    protected function globalSettingBox(Request $request)
    {
        $form = new WidgetForm(System::get_system_values(['login']));
        $form->disableReset();
        $form->action(admin_url('login_setting/postglobal'));


        $form->exmheader(exmtrans('system.password_policy'))->hr();
        $form->description(exmtrans("system.help.password_policy"));

        $form->switchbool('complex_password', exmtrans("system.complex_password"))
            ->help(exmtrans("system.help.complex_password"));

        $form->number('password_expiration_days', exmtrans("system.password_expiration_days"))
            ->default(0)
            ->min(0)
            ->max(999)
            ->help(exmtrans("system.help.password_expiration_days"));

        $form->number('password_history_cnt', exmtrans("system.password_history_cnt"))
            ->default(0)
            ->min(0)
            ->max(20)
            ->help(exmtrans("system.help.password_history_cnt"));
    
        if (!is_nullorempty(config('exment.login_providers'))) {
            $form->exmheader(exmtrans('system.sso_setting'))->hr();

            $form->switchbool('sso_jit', exmtrans("system.sso_jit"))
                ->help(exmtrans("system.help.sso_jit"))
                ->attribute(['data-filtertrigger' =>true]);

            $form->textarea('sso_accept_mail_domain', exmtrans('system.sso_accept_mail_domain'))
                ->help(exmtrans("system.help.sso_accept_mail_domain"))
                ->attribute(['data-filter' => json_encode(['key' => 'sso_jit', 'value' => '1'])])
                ->rows(3)
                ;

            $form->multipleSelect('sso_rolegroups', exmtrans("role_group.header"))
            ->help(exmtrans('system.help.sso_rolegroups'))
            ->options(function ($option) {
                return RoleGroup::all()->pluck('role_group_view_name', 'id');
            })
            ->attribute(['data-filter' => json_encode(['key' => 'sso_jit', 'value' => '1'])]);
        }

        $box = new Box(exmtrans('common.detail_setting'), $form);
        $box->tools(view('exment::tools.button', [
            'href' => admin_url('system'),
            'label' => exmtrans('common.basic_setting'),
            'icon' => 'fa-cog',
        ]));
        return $box;
    }
    
    /**
     * Send data for global setting
     * @param Request $request
     */
    public function postGlobal(Request $request)
    {
        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, ['login'], false, false);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('login_setting'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }
}

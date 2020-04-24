<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Encore\Admin\Layout\Content;
use Carbon\Carbon;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Exment;

class LoginSettingController extends AdminControllerBase
{
    use HasResourceActions, InitializeFormTrait, SystemSettingTrait;

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

        // 2factor box
        if (boolval(config('exment.login_use_2factor', false))) {
            $box = $this->get2factorSettingBox();
            $content->row(new Box(exmtrans("2factor.2factor"), $box->render()));
        }

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
        $grid->column('login_type', exmtrans('login.login_type'))->displayEscape(function($v){
            $enum = LoginType::getEnum($v);
            return $enum ? $enum->transKey('login.login_type_options') : null;
        });
        $grid->column('name', exmtrans('login.login_setting_name'));
        $grid->column('active_flg', exmtrans("plugin.active_flg"))->displayEscape(function ($active_flg) {
            return boolval($active_flg) ? exmtrans("common.available_true") : exmtrans("common.available_false");
        });

        $grid->disableFilter();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        $grid->tools(function (Grid\Tools $tools) {
            $this->setBaseSettingButton($tools);
            $this->setAdvancedSettingButton($tools);
            $this->setApiSettingButton($tools);
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

        $errors = $this->checkLibraries();

        $form->description(exmtrans('common.help.more_help'));

        $form->text('name', exmtrans('login.login_setting_name'))->required();

        if (!isset($id)) {
            $form->radio('login_type', exmtrans('login.login_type'))->options(LoginType::transArrayFilter('login.login_type_options', LoginType::SETTING()))
            ->required()
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans('common.help.init_flg'));
        } else {
            $form->display('login_type_text', exmtrans('login.login_type'));
            $form->hidden('login_type');
        }
        
        $form->switchbool('active_flg', exmtrans("plugin.active_flg"))->default(false);

        $form->embeds('options', exmtrans("login.options"), function (Form\EmbeddedForm $form) use ($login_setting, $errors) {
            ///// toggle
            // if create or oauth
            if (!isset($login_setting) || $login_setting->login_type == LoginType::OAUTH) {
                $this->setOAuthForm($form, $login_setting, $errors);
            }
            if (!isset($login_setting) || $login_setting->login_type == LoginType::SAML) {
                $this->setSamlForm($form, $login_setting, $errors);
            }
            if (!isset($login_setting) || $login_setting->login_type == LoginType::LDAP) {
                $this->setLdapForm($form, $login_setting, $errors);
            }
            

            $form->exmheader(exmtrans('login.user_setting'))->hr();

            $form->select('mapping_user_column', exmtrans("login.mapping_user_column"))
            ->required()
            ->config('allowClear', false)
            ->help(exmtrans('login.help.mapping_user_column'))
            ->options(['user_code' => exmtrans("user.user_code"), 'email' => exmtrans("user.email")])
            ->default('email');

            $form->switchbool('sso_jit', exmtrans("login.sso_jit"))
            ->help(exmtrans("login.help.sso_jit"))
            ->default(false)
            ->attribute(['data-filtertrigger' =>true]);

            $form->multipleSelect('jit_rolegroups', exmtrans("role_group.header"))
            ->help(exmtrans('login.help.jit_rolegroups'))
            ->options(function ($option) {
                return RoleGroup::all()->pluck('role_group_view_name', 'id');
            })
            ->attribute(['data-filter' => json_encode(['key' => 'options_sso_jit', 'value' => '1'])]);
            
            $form->switchbool('update_user_info', exmtrans("login.update_user_info"))
            ->help(exmtrans("login.help.update_user_info"))
            ->default(true);

            if (!isset($login_setting) || in_array($login_setting->login_type, [LoginType::SAML, LoginType::LDAP])) {
                $form->description(exmtrans("login.help.mapping_description"))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                $form->text('mapping_user_code', exmtrans("user.user_code"))
                ->required()
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                $form->text('mapping_user_name', exmtrans("user.user_name"))
                ->required()
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                $form->text('mapping_email', exmtrans("user.email"))
                ->required()
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);
            }


            
            if (!isset($login_setting) || in_array($login_setting->login_type, [LoginType::SAML, LoginType::OAUTH])) {
                $form->exmheader(exmtrans('login.login_button'))->hr()
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);
            
                $form->text('login_button_label', exmtrans('login.login_button_label'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_label'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);
                
                $form->icon('login_button_icon', exmtrans('login.login_button_icon'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_icon'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);

                $form->color('login_button_background_color', exmtrans('login.login_button_background_color'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_background_color'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);

                $form->color('login_button_background_color_hover', exmtrans('login.login_button_background_color_hover'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_background_color_hover'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);

                $form->color('login_button_font_color', exmtrans('login.login_button_font_color'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_font_color'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);

                $form->color('login_button_font_color_hover', exmtrans('login.login_button_font_color_hover'))
                ->default(null)
                ->help(exmtrans('login.help.login_button_font_color_hover'))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);
            }
        })->disableHeader();

        $form->disableReset();

        if(request()->has('test_callback')){
            $form->hidden('logintest_modal')
            ->attribute(['data-widgetmodal_autoload' => route('exment.logintest_modal', ['id' => $id])]);
            $form->ignore('logintest_modal');
        }

        $form->tools(function (Form\Tools $tools) use ($login_setting) {
            if (isset($login_setting)) {
                $tools->append(new Tools\ModalMenuButton(
                    route('exment.logintest_modal', ['id' => $login_setting->id]),
                    [
                        'label' => exmtrans('login.login_test'),
                        'button_class' => 'btn-success',
                        'icon' => 'fa-check-circle',
                    ]
                ));
            }
        });

        
        return $form;
    }

    protected function setOAuthForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::OAUTH)) {
            $form->description($errors[LoginType::OAUTH])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

            return;
        }

        $form->select('oauth_provider_type', exmtrans('login.oauth_provider_type'))
        ->options(LoginProviderType::transKeyArray('login.oauth_provider_type_options'))
        ->required()
        ->attribute(['data-filtertrigger' => true, 'data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $login_provider_caution = '<span class="red">' . exmtrans('login.message.oauth_provider_caution', [
            'url' => getManualUrl('sso'),
        ]) . '</span>';
        $form->description($login_provider_caution)
        ->attribute(['data-filter' => json_encode(['key' => 'options_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->text('oauth_provider_name', exmtrans('login.oauth_provider_name'))
        ->required()
        ->help(exmtrans('login.help.login_provider_name'))
        ->attribute(['data-filter' => json_encode(['key' => 'options_oauth_provider_type', 'value' => [LoginProviderType::OTHER]])]);

        $form->text('oauth_client_id', exmtrans('login.oauth_client_id'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('oauth_client_secret', exmtrans('login.oauth_client_secret'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('oauth_scope', exmtrans('login.oauth_scope'))
        ->help(exmtrans('login.help.scope'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        if (boolval(config('exment.expart_mode', false))) {
            $form->url('oauth_redirect_url', exmtrans('login.redirect_url'))
            ->help(exmtrans('login.help.redirect_url'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);
        } elseif (isset($login_setting)) {
            $form->display('oauth_redirect_url')->default($login_setting->exment_callback_url);
        }
    }


    protected function setLdapForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::LDAP)) {
            $form->description($errors[LoginType::LDAP])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
            return;
        }

        
        if (!isset($login_setting)) {
            $form->text('ldap_name', exmtrans('login.ldap_name'))
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'))
            ->required()
            ->rules(["max:30", "regex:/".Define::RULES_REGEX_SYSTEM_NAME."/", new \Exceedone\Exment\Validator\SamlNameUniqueRule])
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        } else {
            $form->display('ldap_name_text', exmtrans('login.ldap_name'))->default(function () use ($login_setting) {
                return $login_setting->getOption('ldap_name');
            });
            $form->hidden('ldap_name');
        }

        $form->text('ldap_hosts', exmtrans('login.ldap_hosts'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_port', exmtrans('login.ldap_port'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_base_dn', exmtrans('login.ldap_base_dn'))
        ->help(exmtrans('login.help.ldap_base_dn'))
        ->default('dc=example,dc=co,dc=jp')
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_search_key', exmtrans('login.ldap_search_key'))
        ->help(exmtrans('login.help.ldap_search_key'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_account_prefix', exmtrans('login.ldap_account_prefix'))
        ->help(exmtrans('login.help.ldap_account_prefix'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->text('ldap_account_suffix', exmtrans('login.ldap_account_suffix'))
        ->help(exmtrans('login.help.ldap_account_suffix'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        
        $form->switchbool('ldap_use_ssl', exmtrans('login.ldap_use_ssl'))
        ->default(false);

        $form->switchbool('ldap_use_tls', exmtrans('login.ldap_use_tls'))
        ->default(false);
    }


    protected function setSamlForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::SAML)) {
            $form->description($errors[LoginType::SAML])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

            return;
        }
        
        if (!isset($login_setting)) {
            $form->text('saml_name', exmtrans('login.saml_name'))
            ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'))
            ->required()
            ->rules(["max:30", "regex:/".Define::RULES_REGEX_SYSTEM_NAME."/", new \Exceedone\Exment\Validator\SamlNameUniqueRule])
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        } else {
            $form->display('saml_name_text', exmtrans('login.saml_name'))->default(function () use ($login_setting) {
                return $login_setting->getOption('saml_name');
            });
            $form->hidden('saml_name');
        }

        $form->exmheader(exmtrans('login.saml_idp'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->text('saml_idp_entityid', exmtrans('login.saml_idp_entityid'))
        ->help(exmtrans('login.help.saml_idp_entityid'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->url('saml_idp_sso_url', exmtrans('login.saml_idp_sso_url'))
        ->help(exmtrans('login.help.saml_idp_sso_url'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->url('saml_idp_ssout_url', exmtrans('login.saml_idp_ssout_url'))
        ->help(exmtrans('login.help.saml_idp_ssout_url'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_idp_x509', exmtrans('login.saml_idp_x509'))
        ->help(exmtrans('login.help.saml_idp_x509'))
        ->rows(4)
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        

        $form->exmheader(exmtrans('login.saml_sp'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->select('saml_sp_name_id_format', exmtrans('login.saml_sp_name_id_format'))
        ->help(exmtrans('login.help.saml_sp_name_id_format'))
        ->required()
        ->options(Define::SAML_NAME_ID_FORMATS)
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->text('saml_sp_entityid', exmtrans('login.saml_sp_entityid'))
        ->help(exmtrans('login.help.saml_sp_entityid'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_sp_x509', exmtrans('login.saml_sp_x509'))
        ->help(exmtrans('login.help.saml_sp_x509'))
        ->rows(4)
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->textarea('saml_sp_privatekey', exmtrans('login.saml_sp_privatekey'))
        ->help(exmtrans('login.help.saml_privatekey'))
        ->rows(4)
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        

        $form->exmheader(exmtrans('login.saml_option'))->hr()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->switchbool('saml_option_name_id_encrypted', exmtrans("login.saml_option_name_id_encrypted"))
        ->help(exmtrans("login.help.saml_option_name_id_encrypted"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
        
        $form->switchbool('saml_option_authn_request_signed', exmtrans("login.saml_option_authn_request_signed"))
        ->help(exmtrans("login.help.saml_option_authn_request_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->switchbool('saml_option_logout_request_signed', exmtrans("login.saml_option_logout_request_signed"))
        ->help(exmtrans("login.help.saml_option_logout_request_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);

        $form->switchbool('saml_option_logout_response_signed', exmtrans("login.saml_option_logout_response_signed"))
        ->help(exmtrans("login.help.saml_option_logout_response_signed"))
        ->default("0")
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML]])]);
    }



    /**
     * Checking library
     *
     * @return void
     */
    protected function checkLibraries()
    {
        $errors = [];
        if (!class_exists('\\Laravel\\Socialite\\SocialiteServiceProvider')) {
            $errors[] = LoginType::OAUTH();
        }

        if (!class_exists('\\Aacotroneo\\Saml2\\Saml2Auth')) {
            $errors[] = LoginType::SAML();
        }

        if (!class_exists('\\Adldap\\Adldap')) {
            $errors[] = LoginType::LAMP();
        }

        return collect($errors)->mapWithKeys(function ($error) {
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
    
        if (!is_nullorempty(LoginSetting::getAllSettings())) {
            $form->exmheader(exmtrans('login.sso_setting'))->hr();

            $form->switchbool('show_default_login_provider', exmtrans("login.show_default_login_provider"))
                ->help(exmtrans("login.help.show_default_login_provider"))
                ->attribute(['data-filtertrigger' => true]);

            $form->switchbool('sso_redirect_force', exmtrans("login.sso_redirect_force"))
                ->help(exmtrans("login.help.sso_redirect_force"))
                ->attribute(['data-filter' => json_encode(['key' => 'show_default_login_provider', 'value' => '0'])]);

            $form->textarea('sso_accept_mail_domain', exmtrans('login.sso_accept_mail_domain'))
                ->help(exmtrans("login.help.sso_accept_mail_domain"))
                ->rows(3)
                ->attribute(['data-filter' => json_encode(['key' => 'sso_jit', 'value' => '1'])])
                ;
        }

        $box = new Box(exmtrans('common.detail_setting'), $form);
        
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

    /**
     * Showing login test modal
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function loginTestModal(Request $request, $id)
    {
        $login_setting = LoginSetting::find($id);

        $form = $login_setting->getLoginServiceClassName()::getTestForm($login_setting);
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('login.login_test'),
            'showReset' => false,
            'showSubmit' => $login_setting->login_type == LoginType::LDAP,
        ]);
    }

    /**
     * execute login test for form
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function loginTestForm(Request $request, $id)
    {
        $login_setting = LoginSetting::find($id);
        
        return $login_setting->getLoginServiceClassName()::loginTest($request, $login_setting);
    }

    /**
     * execute login test for SSO
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function loginTestSso(Request $request, $id)
    {
        $login_setting = LoginSetting::find($id);
        
        return $login_setting->getLoginServiceClassName()::loginTest($request, $login_setting);
    }
    /**
     * execute login test for callback
     *
     * @param Request $request
     * @param [type] $id
     * @return void
     */
    public function loginTestCallback(Request $request, Content $content, $id)
    {
        $login_setting = LoginSetting::find($id);
        
        $message = $login_setting->getLoginServiceClassName()::loginTestCallback($request, $login_setting);
        session([Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE => $message]);

        return redirect(admin_urls_query('login_setting', $id, 'edit', ['test_callback' => 1]));
        
        // $content = $this->edit($request, $content, $id);

        // return $content->row('<input type="hidden" data-widgetmodal_autoload="' . route('exment.logintest_modal', ['id' => $id]) .'"/>');
    }



    
    /**
     * get 2factor setting box.
     *
     * @return Content
     */
    protected function get2factorSettingBox()
    {
        $form = new WidgetForm(System::get_system_values(['2factor']));
        $form->action(route('exment.post2factor'));
        $form->disableReset();

        $form->description(exmtrans("2factor.message.description", getManualUrl('login_2factor_setting')));

        $form->switchbool('login_use_2factor', exmtrans("2factor.login_use_2factor"))
            ->help(exmtrans("2factor.help.login_use_2factor"))
            ->attribute(['data-filtertrigger' =>true]);

        $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider"))
            ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
            ->config('allowClear', false)
            ->default(Login2FactorProviderType::EMAIL)
            ->help(exmtrans("2factor.help.login_2factor_provider"))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        $form->ajaxButton('login_2factor_verify_button', exmtrans("2factor.submit_verify_code"))
            ->help(exmtrans("2factor.help.submit_verify_code"))
            ->url(route('exment.2factor_verify'))
            ->button_class('btn-sm btn-info')
            ->button_label(exmtrans('2factor.submit_verify_code'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        $form->text('login_2factor_verify_code', exmtrans("2factor.login_2factor_verify_code"))
            ->required()
            ->help(exmtrans("2factor.help.login_2factor_verify_code"))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1'])]);

        return $form;
    }


    
    /**
     * Send data
     * @param Request $request
     */
    public function post2factor(Request $request)
    {
        $login_2factor_verify_code = $request->get('login_2factor_verify_code');
        if (boolval($request->get('login_use_2factor'))) {
            // check verifyCode
            if (!Auth2factorService::verifyCode('system', $login_2factor_verify_code)) {
                // error
                return back()->withInput()->withErrors([
                    'login_2factor_verify_code' => exmtrans('2factor.message.verify_failed')
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $inputs = $request->all(System::get_system_keys(['2factor']));
            
            // set system_key and value
            foreach ($inputs as $k => $input) {
                System::{$k}($input);
            }

            DB::commit();

            if (isset($login_2factor_verify_code)) {
                Auth2factorService::deleteCode('system', $login_2factor_verify_code);
            }

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('login_setting'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * 2factor verify
     *
     * @return void
     */
    public function auth_2factor_verify()
    {
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinute(60);
        
        // send verify
        try {
            if (!Auth2factorService::addAndSendVerify('system', $verify_code, $valid_period_datetime, MailKeyName::VERIFY_2FACTOR_SYSTEM, [
                'verify_code' => $verify_code,
                'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
            ])) {
                // show warning message
                return getAjaxResponse([
                    'result'  => false,
                    'toastr' => exmtrans('error.mailsend_failed'),
                    'reload' => false,
                ]);
            }
        } catch (NoMailTemplateException $ex) {
            // show warning message
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.no_mail_template'),
                'reload' => false,
            ]);
        }
        // throw mailsend Exception
        catch (\Swift_TransportException $ex) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('error.mailsend_failed'),
                'reload' => false,
            ]);
        }

        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        return getAjaxResponse([
            'result'  => true,
            'toastr' => exmtrans('common.message.sendmail_succeeded'),
            'reload' => false,
        ]);
    }
    
}

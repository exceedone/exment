<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login as LoginServiceBase;
use Encore\Admin\Layout\Content;
use Carbon\Carbon;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class LoginSettingController extends AdminControllerBase
{
    use HasResourceActions;
    use InitializeFormTrait;

    public function __construct()
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
            $form = $this->get2factorSettingForm();
            $content->row(new Box(exmtrans("2factor.2factor"), $form->render()));
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
        $grid = new Grid(new LoginSetting());
        $grid->column('login_type', exmtrans('login.login_type'))->display(function ($v) {
            $enum = LoginType::getEnum($v);
            return $enum ? $enum->transKey('login.login_type_options') : null;
        });
        $grid->column('login_view_name', exmtrans('login.login_view_name'));
        $grid->column('active_flg', exmtrans("plugin.active_flg"))->display(function ($active_flg) {
            return \Exment::getTrueMark($active_flg);
        })->escape(false);

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();

            $filter->equal('login_type', exmtrans("login.login_type"))->select(LoginType::transArrayFilter('login.login_type_options', LoginType::SETTING()));
            $filter->like('login_view_name', exmtrans("login.login_view_name"));
            $filter->like('active_flg', exmtrans("plugin.active_flg"))->radio(\Exment::getYesNoAllOption());
        });

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        $grid->tools(function (Grid\Tools $tools) {
            $tools->prepend(new Tools\SystemChangePageMenu());
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
        $form = new Form(new LoginSetting());
        $login_setting = LoginSetting::getEloquent($id);

        $errors = $this->checkLibraries();

        $form->descriptionHtml(exmtrans('common.help.more_help'));

        $form->text('login_view_name', exmtrans('login.login_view_name'))->required();

        if (!isset($id)) {
            $form->radio('login_type', exmtrans('login.login_type'))->options(LoginType::transArrayFilter('login.login_type_options', LoginType::SETTING()))
                ->required()
                ->attribute(['data-filtertrigger' =>true])
                ->help(exmtrans('common.help.init_flg'));
        } else {
            $form->display('login_type_text', exmtrans('login.login_type'));
            $form->hidden('login_type');

            $form->display('active_flg', exmtrans("plugin.active_flg"))
            ->customFormat(function ($value) {
                return getYesNo($value);
            });
        }

        $form->embeds('options', exmtrans("login.options"), function (Form\EmbeddedForm $form) use ($login_setting, $errors) {
            $user_custom_columns = CustomTable::getEloquent(SystemTableName::USER)->custom_columns_cache;
            ///// toggle
            // if create or oauth
            if (!isset($login_setting) || $login_setting->login_type == LoginType::OAUTH) {
                LoginServiceBase\OAuth\OAuthService::setOAuthForm($form, $login_setting, $errors);
            }
            if (!isset($login_setting) || $login_setting->login_type == LoginType::SAML) {
                LoginServiceBase\Saml\SamlService::setSamlForm($form, $login_setting, $errors);
            }
            if (!isset($login_setting) || $login_setting->login_type == LoginType::LDAP) {
                LoginServiceBase\Ldap\LdapService::setLdapForm($form, $login_setting, $errors);
            }



            if (!isset($login_setting) || in_array($login_setting->login_type, [LoginType::SAML, LoginType::LDAP])) {
                $form->exmheader(exmtrans('login.mapping_setting'))->hr()
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                $form->descriptionHtml(exmtrans("login.help.mapping_description"))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                // setting mapping list
                foreach ($user_custom_columns as $user_custom_column) {
                    $field = $form->text("mapping_column_{$user_custom_column->column_name}", $user_custom_column->column_view_name)
                        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::LDAP]])]);

                    if ($user_custom_column->required) {
                        $field->required();
                    }
                }
            }


            $form->exmheader(exmtrans('login.user_setting'))->hr();

            // showing "user"'s Custom column and unique
            $form->select('mapping_user_column', exmtrans("login.mapping_user_column"))
            ->required()
            ->disableClear()
            ->help(exmtrans('login.help.mapping_user_column'))
            ->options(function ($column) use ($user_custom_columns) {
                return $user_custom_columns->filter(function ($custom_column) {
                    return boolval($custom_column->unique);
                })->pluck('column_view_name', 'column_name');
            })->default('email');

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
            ->default(false);


            $form->exmheader(exmtrans('login.login_button'))->hr();

            $form->text('login_button_label', exmtrans('login.login_button_label'))
            ->default(null)
            ->rules('max:30')
            ->help(exmtrans('login.help.login_button_label'));

            $form->icon('login_button_icon', exmtrans('login.login_button_icon'))
            ->default(null)
            ->help(exmtrans('login.help.login_button_icon'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::SAML, LoginType::OAUTH]])]);

            $form->color('login_button_background_color', exmtrans('login.login_button_background_color'))
            ->default(null)
            ->help(exmtrans('login.help.login_button_background_color'));

            $form->color('login_button_background_color_hover', exmtrans('login.login_button_background_color_hover'))
            ->default(null)
            ->help(exmtrans('login.help.login_button_background_color_hover'));

            $form->color('login_button_font_color', exmtrans('login.login_button_font_color'))
            ->default(null)
            ->help(exmtrans('login.help.login_button_font_color'));

            $form->color('login_button_font_color_hover', exmtrans('login.login_button_font_color_hover'))
            ->default(null)
            ->help(exmtrans('login.help.login_button_font_color_hover'));

            // setting for each settings of login oauth. --------------------------------------------------
            // Form options area -- start
            $form->html('<div class="form_dynamic_options">')->plain();

            // get provider_name
            $request = request();
            $provider_name = null;
            if (isset($login_setting) && $login_setting->login_type == LoginType::OAUTH) {
                $provider_name = $login_setting->provider_name;
            } elseif ($request->get('login_type') == LoginType::OAUTH) {
                $provider_name = array_get($request->all(), 'options.oauth_provider_type') == 'other' ? array_get($request->all(), 'options.oauth_provider_name') : array_get($request->all(), 'options.oauth_provider_type');
            } elseif ($request->old('login_type') == LoginType::OAUTH) {
                $provider_name = array_get($request->old(), 'options.oauth_provider_type') == 'other' ? array_get($request->old(), 'options.oauth_provider_name') : array_get($request->old(), 'options.oauth_provider_type');
            }
            if (!is_nullorempty($provider_name)) {
                LoginServiceBase\OAuth\OAuthService::setLoginSettingForm($provider_name, $form);
            }
            // Form options area -- End
            $form->html('</div>')->plain();
        })->disableHeader();

        $form->disableReset();

        if (request()->has('test_callback') && session()->has(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE)) {
            $form->hidden('logintest_modal')
            ->attribute(['data-widgetmodal_autoload' => route('exment.logintest_modal', ['id' => $id])]);
            $form->ignore('logintest_modal');
        }

        $form->tools(function (Form\Tools $tools) use ($login_setting) {
            $tools->append(new Tools\SystemChangePageMenu());

            if (isset($login_setting) && !is_null($className = $login_setting->getLoginServiceClassName())) {
                $tools->append(new Tools\ModalMenuButton(
                    route('exment.logintest_modal', ['id' => $login_setting->id]),
                    [
                        'label' => exmtrans('login.login_test'),
                        'button_class' => 'btn-success',
                        'icon' => 'fa-check-circle',
                    ]
                ));

                $className::appendActivateSwalButton($tools, $login_setting);
            }
        });

        $form->saved(function (Form $form) {
            return redirect($this->getEditUrl($form->model()->id));
        });


        return $form;
    }



    /**
     * Checking library
     *
     * @return \Illuminate\Support\Collection
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
            $errors[] = LoginType::LDAP();
        }

        /** @var Collection $collection */
        $collection =  collect($errors)->mapWithKeys(function ($error) {
            return [$error->getValue() => '<span class="red">' . exmtrans('login.message.not_install_library', [
                'name' => $error->transKey('login.login_type_options'),
                'url' => getManualUrl('login_'.$error->getValue()),
            ]) . '</span>'];
        });
        return $collection;
    }

    /**
     * Send data for global setting
     *
     * @param Request $request
     * @return Box
     */
    protected function globalSettingBox(Request $request)
    {
        $form = $this->globalSettingForm($request);
        $box = new Box(exmtrans('common.detail_setting'), $form);
        return $box;
    }


    /**
     * Get form for global setting
     *
     * @param Request $request
     * @return WidgetForm
     */
    protected function globalSettingForm(Request $request): WidgetForm
    {
        $form = new WidgetForm(System::get_system_values(['login']));
        $form->disableReset();
        $form->action(route('exment.postglobal'));


        $form->exmheader(exmtrans('system.password_policy'))->hr();
        $form->descriptionHtml(exmtrans("system.help.password_policy"));

        $form->switchbool('complex_password', exmtrans("system.complex_password"))
            ->help(exmtrans("system.help.complex_password"));

        $form->number('password_expiration_days', exmtrans("system.password_expiration_days"))
            ->default(0)
            ->between(0, 999)
            ->help(exmtrans("system.help.password_expiration_days"));

        $form->switchbool('first_change_password', exmtrans("system.first_change_password"))
            ->help(exmtrans("system.help.first_change_password"));

        $form->number('password_history_cnt', exmtrans("system.password_history_cnt"))
            ->default(0)
            ->between(0, 20)
            ->help(exmtrans("system.help.password_history_cnt"));

        $form->exmheader(exmtrans('system.login_page_view'))->hr();

        $form->color('login_background_color', exmtrans('system.login_background_color'))
            ->default(null)
            ->help(exmtrans('system.help.login_background_color'));

        $fileOption = array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => true,
                'deleteUrl' => admin_urls('system', 'filedelete'),
                'deleteExtraData'      => [
                    '_token'           => csrf_token(),
                    '_method'          => 'PUT',
                    'delete_flg'       => 'login_page_image'
                ]
            ]
        );
        $form->image('login_page_image', exmtrans("system.login_page_image"))
            ->help(exmtrans("system.help.login_page_image"))
            ->options($fileOption)
            ->removable()
            ->attribute(['accept' => "image/*"])
        ;

        $form->select('login_page_image_type', exmtrans("system.login_page_image_type"))
            ->help(exmtrans("system.help.login_page_image_type"))
            ->disableClear()
            ->options(Enums\LoginBgImageType::transArray('system.login_page_image_type_options'))
        ;


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
            ;
        }

        return $form;
    }

    /**
     * Send data for global setting
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|true
     * @throws \Throwable
     */
    public function postGlobal(Request $request)
    {
        // validation
        $form = $this->globalSettingForm($request);
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, ['login'], false, false);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(route('exment.login_setting.index'));
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * Showing login test modal
     *
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function loginTestModal(Request $request, $id)
    {
        $login_setting = LoginSetting::getEloquent($id);

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
     * @param $id
     * @return mixed
     */
    public function loginTestForm(Request $request, $id)
    {
        $login_setting = LoginSetting::getEloquent($id);

        return $login_setting->getLoginServiceClassName()::loginTest($request, $login_setting);
    }

    /**
     * execute login test for SSO
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function loginTestSso(Request $request, $id)
    {
        try {
            $login_setting = LoginSetting::getEloquent($id);

            return $login_setting->getLoginServiceClassName()::loginTest($request, $login_setting);
        } catch (SsoLoginErrorException $ex) {
            \Log::error($ex);

            session([Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE => $ex->getSsoAdminErrorMessage()]);

            return redirect($this->getEditUrl($id, true));

            // if error, redirect edit page
        } catch (\Exception $ex) {
            \Log::error($ex);

            list($result, $message, $adminMessage, $custom_login_user) = LoginService::getLoginResult(false, exmtrans('login.sso_provider_error'), [$ex]);
            session([Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE => $adminMessage]);

            return redirect($this->getEditUrl($id, true));

            // if error, redirect edit page
        }
    }

    /**
     * execute login test for callback
     *
     * @param Request $request
     * @param Content $content
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function loginTestCallback(Request $request, Content $content, $id)
    {
        try {
            $login_setting = LoginSetting::getEloquent($id);

            list($result, $message, $adminMessage, $custom_login_user) = $login_setting->getLoginServiceClassName()::loginCallback($request, $login_setting, true);
            session([Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE => $adminMessage]);

            return redirect($this->getEditUrl($id, true));
        } catch (\Exception $ex) {
            \Log::error($ex);

            list($result, $message, $adminMessage, $custom_login_user) = LoginService::getLoginResult(false, exmtrans('login.sso_provider_error'), [$ex]);
            session([Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE => $adminMessage]);

            return redirect($this->getEditUrl($id, true));
        }
    }


    protected function getEditUrl($id, $testCallback = false)
    {
        $uri = route('exment.login_setting.edit', ['id' => $id]);
        if (!$testCallback) {
            admin_toastr(trans('admin.save_succeeded'));
            return $uri;
        }
        return  $uri . '?' . http_build_query(['test_callback' => 1]);
    }

    /**
     * Active login setting
     *
     * @param Request $request
     * @param string|int|null $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function activate(Request $request, $id)
    {
        return $this->toggleActivate($request, $id, true);
    }

    /**
     * Deactive login setting
     *
     * @param Request $request
     * @param string|int|null $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deactivate(Request $request, $id)
    {
        return $this->toggleActivate($request, $id, false);
    }

    /**
     * Toggle activate and deactivate
     *
     * @param Request $request
     * @param string $id
     * @param boolean $active_flg
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function toggleActivate(Request $request, $id, bool $active_flg)
    {
        $login_setting = LoginSetting::getEloquent($id);
        $login_setting->active_flg = $active_flg;
        $login_setting->save();

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }

    /**
     * get 2factor setting box.
     *
     * @return WidgetForm
     */
    protected function get2factorSettingForm(): WidgetForm
    {
        $form = new WidgetForm(System::get_system_values(['2factor']));
        $form->action(route('exment.post2factor'));
        $form->disableReset();

        $form->descriptionHtml(exmtrans("2factor.message.description", getManualUrl('login_2factor_setting')));

        $form->switchbool('login_use_2factor', exmtrans("2factor.login_use_2factor"))
            ->help(exmtrans("2factor.help.login_use_2factor"))
            ->attribute(['data-filtertrigger' =>true]);

        $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider"))
            ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
            ->disableClear()
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

        // validation
        $form = $this->get2factorSettingForm();
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
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
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }

    /**
     * 2factor verify
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function auth_2factor_verify()
    {
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinutes(60);

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
        catch (TransportExceptionInterface $ex) {
            \Log::error($ex);
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


    /**
     * Get login option form
     *
     * @param Request $request
     * @return array
     */
    public function loginOptionHtml(Request $request)
    {
        $val = $request->get('val');
        $form_type = $request->get('form_type');
        $form_uniqueName = $request->get('form_uniqueName');
        $id = $request->route('id');

        $form = new Form(new LoginSetting());
        $form->setUniqueName($form_uniqueName)->embeds('options', exmtrans("login.options"), function ($form) use ($val) {
            // Form options area -- start
            $form->html('<div class="form_dynamic_options_response">')->plain();
            LoginServiceBase\OAuth\OAuthService::setLoginSettingForm($val, $form);
            $form->html('</div>')->plain();
        });

        $body = $form->render();
        $script = \Admin::purescript()->render();
        return [
            'body'  => $body,
            'script' => $script,
        ];
    }
}

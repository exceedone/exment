<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
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
use Exceedone\Exment\Services\Auth2factor\Auth2factorLockService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\GeoIp\CountryList;
use Exceedone\Exment\Services\GeoIp\GeoIpService;
use Exceedone\Exment\Services\Login\IpAddressList;
use Exceedone\Exment\Services\Login\LoginCaptchaService;
use Exceedone\Exment\Services\Login\LoginCountryRestrictionService;
use Exceedone\Exment\Services\Login\LoginFailureService;
use Exceedone\Exment\Services\Login\LoginIpBlockService;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login as LoginServiceBase;
use ExmentAdminCore\Admin\Layout\Content;
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

        // login restriction by country
        $content->row(new Box(exmtrans('login.country_restriction'), $this->getCountryRestrictionSettingForm($request)->render()));

        // protection against brute-force attacks
        $content->row(new Box(exmtrans('login.brute_force'), $this->getBruteForceSettingForm($request)->render()));

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
    // @phpstan-ignore-next-line
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
                // @phpstan-ignore-next-line
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
                        'button_class' => 'btn-success p-2',
                        'icon' => 'fa-check-circle',
                    ]
                ));

                $className::appendActivateSwalButton($tools, $login_setting);
            }
        });

        // Server-side "required" validation for OAuth options.
        // Field::required() in laravel-admin only adds the HTML5 "required" attribute
        // and the label asterisk; it does NOT add a validation rule (see Field::required()).
        // So the browser check can be removed via devtools and empty values get saved.
        // These fields are toggled by "login_type", so we validate conditionally here
        // (the embedded form validator can't see the top-level "login_type").
        $form->validatorSavingCallback(function ($input, $message, $form) {
            if (array_get($input, 'login_type') != LoginType::OAUTH) {
                return;
            }

            $rules = [
                'options.oauth_provider_type' => 'required',
                'options.oauth_client_id'     => 'required',
                'options.oauth_client_secret' => 'required',
            ];
            $attributes = [
                'options.oauth_provider_type' => exmtrans('login.oauth_provider_type'),
                'options.oauth_client_id'     => exmtrans('login.oauth_client_id'),
                'options.oauth_client_secret' => exmtrans('login.oauth_client_secret'),
            ];

            // "provider name" is required only when the provider type is "other".
            if (array_get($input, 'options.oauth_provider_type') == Enums\LoginProviderType::OTHER) {
                $rules['options.oauth_provider_name'] = 'required';
                $attributes['options.oauth_provider_name'] = exmtrans('login.oauth_provider_name');
            }

            $validator = \Validator::make($input, $rules, [], $attributes);
            if ($validator->fails()) {
                $message->merge($validator->errors());
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
    // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
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
     * @param mixed $id
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
     * @param mixed $id
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
     * @param mixed $id
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
     * @param mixed $id
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


    // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
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
     * Get the setting form of the login restriction by country.
     *
     * @param Request $request
     * @return WidgetForm
     */
    protected function getCountryRestrictionSettingForm(Request $request): WidgetForm
    {
        // keep the entered values when the saving failed
        $values = System::get_system_values([LoginCountryRestrictionService::SETTING_GROUP]);
        foreach (array_keys($values) as $key) {
            $key = (string)$key;
            if ($request->session()->hasOldInput($key)) {
                $values[$key] = $request->old($key);
            }
        }

        $form = new WidgetForm($values);
        $form->action(route('exment.post_login_country'));
        $form->disableReset();

        $form->descriptionHtml($this->getCountryRestrictionDescriptionHtml($request));

        $form->switchbool('login_country_restriction', exmtrans('login.login_country_restriction'))
            ->help(exmtrans('login.help.login_country_restriction'))
            ->attribute(['data-filtertrigger' => true]);

        $filter = ['data-filter' => json_encode(['key' => 'login_country_restriction', 'value' => '1'])];

        $form->multipleSelect('login_country_allowed', exmtrans('login.login_country_allowed'))
            ->options(CountryList::getOptions())
            ->help(exmtrans('login.help.login_country_allowed'))
            ->attribute($filter);

        $form->switchbool('login_country_unknown_allowed', exmtrans('login.login_country_unknown_allowed'))
            ->help(exmtrans('login.help.login_country_unknown_allowed'))
            ->attribute($filter);

        $form->textarea('login_country_allowed_ips', exmtrans('login.login_country_allowed_ips'))
            ->rows(3)
            ->help(exmtrans('login.help.login_country_allowed_ips'))
            ->attribute($filter);

        return $form;
    }

    /**
     * Description of the login restriction by country: the GeoIP database status, and the IP address / country of the current access.
     *
     * @param Request $request
     * @return string HTML
     */
    protected function getCountryRestrictionDescriptionHtml(Request $request): string
    {
        $html = esc_html(exmtrans('login.help.country_restriction'));

        if (!GeoIpService::isAvailable()) {
            return $html . '<br /><span class="red">' . esc_html(exmtrans('login.help.country_geoip_not_available')) . '</span>';
        }

        $geo = app(LoginCountryRestrictionService::class)->lookup($request->ip());
        $country = is_nullorempty($geo['country_code']) ? exmtrans('login.country_unknown') : (CountryList::getName($geo['country_code']) ?? $geo['country_code']) . " ({$geo['country_code']})";
        return $html . '<br />' . esc_html(exmtrans('login.help.country_current_ip', $request->ip(), $country));
    }

    /**
     * Save the login restriction by country.
     * Not saved if the administrator saving it could not log in from the current IP address any more.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postCountryRestriction(Request $request)
    {
        $service = app(LoginCountryRestrictionService::class);
        $enabled = boolval($request->input('login_country_restriction'));
        // turned off: the fields below the switch are hidden and not posted, so the saved countries / IP addresses are kept
        // (also the default countries, when never saved)
        $ipsText = $request->exists('login_country_allowed_ips') ? $request->input('login_country_allowed_ips') : System::login_country_allowed_ips();
        $settings = LoginCountryRestrictionService::makeSettings(
            $enabled,
            $enabled ? $request->input('login_country_allowed', []) : System::login_country_allowed(),
            boolval($request->input('login_country_unknown_allowed')),
            $ipsText
        );

        $errors = [];
        if (count(array_diff($settings['countries'], array_keys(CountryList::COUNTRIES))) > 0) {
            $errors['login_country_allowed'] = exmtrans('login.message.country_invalid');
        }
        $invalidIps = LoginCountryRestrictionService::getInvalidIps($ipsText);
        if (count($invalidIps) > 0) {
            $errors['login_country_allowed_ips'] = exmtrans('login.message.country_ips_invalid', implode(', ', $invalidIps));
        }

        if ($settings['enabled'] && count($errors) == 0) {
            if (count($settings['countries']) == 0) {
                $errors['login_country_allowed'] = exmtrans('login.message.country_allowed_required');
            } elseif (!GeoIpService::isAvailable()) {
                // every IP address would be "unknown"
                $errors['login_country_restriction'] = exmtrans('login.help.country_geoip_not_available');
            } elseif (!($result = $service->check($request->ip(), $settings))['allowed']) {
                $country = $result['geo']['country_code'] ?? exmtrans('login.country_unknown');
                $errors['login_country_restriction'] = exmtrans('login.message.country_self_blocked', $request->ip(), $country);
            }
        }

        if (count($errors) > 0) {
            admin_toastr(exmtrans('common.message.error_execute'), 'error');
            return back()->withInput()->withErrors($errors);
        }

        System::login_country_restriction($settings['enabled']);
        System::login_country_allowed($settings['countries']);
        System::login_country_unknown_allowed($settings['unknown_allowed']);
        System::login_country_allowed_ips(implode("\r\n", $settings['ips']));

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('login_setting'));
    }

    /**
     * Integer settings of the protection against brute-force attacks, and their ranges. key: setting name, value: [min, max]
     *
     * @return array<string, array{0: int, 1: int}>
     */
    protected static function getBruteForceIntegerSettings(): array
    {
        return [
            'login_failure_minutes' => [1, LoginIpBlockService::FAILURE_MINUTES_MAX],
            'login_ip_block_failures' => [1, LoginIpBlockService::FAILURES_MAX],
            'login_ip_block_minutes' => [1, LoginIpBlockService::BLOCK_MINUTES_MAX],
            'login_ip_block_minutes_2' => [1, LoginIpBlockService::BLOCK_MINUTES_MAX],
            'login_ip_block_minutes_3' => [1, LoginIpBlockService::BLOCK_MINUTES_MAX],
            'login_captcha_failures' => [1, LoginCaptchaService::FAILURES_MAX],
            'login_multi_ip_count' => [LoginFailureService::MULTI_IP_COUNT_MIN, LoginFailureService::MULTI_IP_COUNT_MAX],
            'login_multi_ip_minutes' => [1, LoginFailureService::MULTI_IP_MINUTES_MAX],
            'login_2factor_lock_failures' => [1, Auth2factorLockService::FAILURES_MAX],
            'login_2factor_lock_minutes' => [1, Auth2factorLockService::LOCK_MINUTES_MAX],
        ];
    }

    /**
     * Switches of the protection against brute-force attacks.
     *
     * @return array<string>
     */
    protected static function getBruteForceBooleanSettings(): array
    {
        return [
            'login_ip_block',
            'login_ip_block_progressive',
            'login_ip_block_notify',
            'login_captcha',
            'login_multi_ip_warning',
            'login_2factor_lock',
            'login_brute_force_notify_mail',
        ];
    }

    /**
     * Get the setting form of the protection against brute-force attacks. (IP block, CAPTCHA, warning of many IP addresses)
     *
     * @param Request $request
     * @return WidgetForm
     */
    protected function getBruteForceSettingForm(Request $request): WidgetForm
    {
        // keep the entered values when the saving failed
        $values = System::get_system_values([LoginIpBlockService::SETTING_GROUP]);
        foreach (array_keys($values) as $key) {
            $key = (string)$key;
            if ($request->session()->hasOldInput($key)) {
                $values[$key] = $request->old($key);
            }
        }
        $ranges = static::getBruteForceIntegerSettings();

        $form = new WidgetForm($values);
        $form->action(route('exment.post_login_brute_force'));
        $form->disableReset();

        $form->descriptionHtml(esc_html(exmtrans('login.help.brute_force'))
            . '<br />' . esc_html(exmtrans('login.help.brute_force_current_ip', $request->ip())));

        $form->number('login_failure_minutes', exmtrans('login.login_failure_minutes'))
            ->help(exmtrans('login.help.login_failure_minutes', $ranges['login_failure_minutes'][1]))
            ->min($ranges['login_failure_minutes'][0])
            ->max($ranges['login_failure_minutes'][1]);

        // IP block
        $form->exmheader(exmtrans('login.ip_block'))->hr();
        $form->switchbool('login_ip_block', exmtrans('login.login_ip_block'))
            ->help(exmtrans('login.help.login_ip_block'))
            ->attribute(['data-filtertrigger' => true]);

        $ipBlockFilter = ['key' => 'login_ip_block', 'value' => '1'];
        $form->number('login_ip_block_failures', exmtrans('login.login_ip_block_failures'))
            ->help(exmtrans('login.help.login_ip_block_failures', $ranges['login_ip_block_failures'][1]))
            ->min($ranges['login_ip_block_failures'][0])
            ->max($ranges['login_ip_block_failures'][1])
            ->attribute(['data-filter' => json_encode($ipBlockFilter)]);
        $form->number('login_ip_block_minutes', exmtrans('login.login_ip_block_minutes'))
            ->help(exmtrans('login.help.login_ip_block_minutes', $ranges['login_ip_block_minutes'][1]))
            ->min($ranges['login_ip_block_minutes'][0])
            ->max($ranges['login_ip_block_minutes'][1])
            ->attribute(['data-filter' => json_encode($ipBlockFilter)]);
        $form->switchbool('login_ip_block_progressive', exmtrans('login.login_ip_block_progressive'))
            ->help(exmtrans('login.help.login_ip_block_progressive', LoginIpBlockService::RECURRENCE_HOURS))
            ->attribute(['data-filtertrigger' => true, 'data-filter' => json_encode($ipBlockFilter)]);

        $progressiveFilter = json_encode([$ipBlockFilter, ['key' => 'login_ip_block_progressive', 'value' => '1']]);
        $form->number('login_ip_block_minutes_2', exmtrans('login.login_ip_block_minutes_2'))
            ->min($ranges['login_ip_block_minutes_2'][0])
            ->max($ranges['login_ip_block_minutes_2'][1])
            ->attribute(['data-filter' => $progressiveFilter]);
        $form->number('login_ip_block_minutes_3', exmtrans('login.login_ip_block_minutes_3'))
            ->help(exmtrans('login.help.login_ip_block_minutes_3'))
            ->min($ranges['login_ip_block_minutes_3'][0])
            ->max($ranges['login_ip_block_minutes_3'][1])
            ->attribute(['data-filter' => $progressiveFilter]);

        $form->textarea('login_ip_block_allowed_ips', exmtrans('login.login_ip_block_allowed_ips'))
            ->rows(3)
            ->help(exmtrans('login.help.login_ip_block_allowed_ips'))
            ->attribute(['data-filter' => json_encode($ipBlockFilter)]);
        $form->switchbool('login_ip_block_notify', exmtrans('login.login_ip_block_notify'))
            ->help(exmtrans('login.help.login_ip_block_notify'))
            ->attribute(['data-filter' => json_encode($ipBlockFilter)]);
        $form->html('<a href="' . esc_html(admin_url('login_ip_block')) . '"><i class="fa fa-ban"></i> ' . esc_html(exmtrans('login.ip_block_list')) . '</a>');

        // CAPTCHA
        $form->exmheader(exmtrans('login.captcha_header'))->hr();
        if (!LoginCaptchaService::isAvailable()) {
            $form->descriptionHtml('<span class="red">' . esc_html(exmtrans('login.help.captcha_not_available')) . '</span>');
        }
        $form->switchbool('login_captcha', exmtrans('login.login_captcha'))
            ->help(exmtrans('login.help.login_captcha'))
            ->attribute(['data-filtertrigger' => true]);
        $form->number('login_captcha_failures', exmtrans('login.login_captcha_failures'))
            ->help(exmtrans('login.help.login_captcha_failures', $ranges['login_captcha_failures'][1]))
            ->min($ranges['login_captcha_failures'][0])
            ->max($ranges['login_captcha_failures'][1])
            ->attribute(['data-filter' => json_encode(['key' => 'login_captcha', 'value' => '1'])]);

        // warning of an account tried from many IP addresses
        $form->exmheader(exmtrans('login.multi_ip_header'))->hr();
        $form->switchbool('login_multi_ip_warning', exmtrans('login.login_multi_ip_warning'))
            ->help(exmtrans('login.help.login_multi_ip_warning'))
            ->attribute(['data-filtertrigger' => true]);
        $multiIpFilter = json_encode(['key' => 'login_multi_ip_warning', 'value' => '1']);
        $form->number('login_multi_ip_count', exmtrans('login.login_multi_ip_count'))
            ->help(exmtrans('login.help.login_multi_ip_count', $ranges['login_multi_ip_count'][0], $ranges['login_multi_ip_count'][1]))
            ->min($ranges['login_multi_ip_count'][0])
            ->max($ranges['login_multi_ip_count'][1])
            ->attribute(['data-filter' => $multiIpFilter]);
        $form->number('login_multi_ip_minutes', exmtrans('login.login_multi_ip_minutes'))
            ->help(exmtrans('login.help.login_multi_ip_minutes', $ranges['login_multi_ip_minutes'][1]))
            ->min($ranges['login_multi_ip_minutes'][0])
            ->max($ranges['login_multi_ip_minutes'][1])
            ->attribute(['data-filter' => $multiIpFilter]);

        // limit of wrong codes of 2factor authentication
        $form->exmheader(exmtrans('login.login_2factor_lock_header'))->hr();
        $form->switchbool('login_2factor_lock', exmtrans('login.login_2factor_lock'))
            ->help(exmtrans('login.help.login_2factor_lock'))
            ->attribute(['data-filtertrigger' => true]);
        $twoFactorLockFilter = json_encode(['key' => 'login_2factor_lock', 'value' => '1']);
        $form->number('login_2factor_lock_failures', exmtrans('login.login_2factor_lock_failures'))
            ->help(exmtrans('login.help.login_2factor_lock_failures', $ranges['login_2factor_lock_failures'][1]))
            ->min($ranges['login_2factor_lock_failures'][0])
            ->max($ranges['login_2factor_lock_failures'][1])
            ->attribute(['data-filter' => $twoFactorLockFilter]);
        $form->number('login_2factor_lock_minutes', exmtrans('login.login_2factor_lock_minutes'))
            ->help(exmtrans('login.help.login_2factor_lock_minutes', $ranges['login_2factor_lock_minutes'][1]))
            ->min($ranges['login_2factor_lock_minutes'][0])
            ->max($ranges['login_2factor_lock_minutes'][1])
            ->attribute(['data-filter' => $twoFactorLockFilter]);

        // mail
        $form->exmheader(exmtrans('login.brute_force_notify_header'))->hr();
        $form->switchbool('login_brute_force_notify_mail', exmtrans('login.login_brute_force_notify_mail'))
            ->help(exmtrans('login.help.login_brute_force_notify_mail'));

        return $form;
    }

    /**
     * Save the protection against brute-force attacks.
     * A setting not posted (hidden on the form while its switch is off) keeps the saved value.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postBruteForce(Request $request)
    {
        $errors = [];

        // integers: in the range. Empty keeps the saved value.
        $integers = [];
        foreach (static::getBruteForceIntegerSettings() as $key => $range) {
            $value = $request->input($key);
            if (is_null($value) || $value === '') {
                $integers[$key] = System::{$key}();
                continue;
            }
            if (!is_string($value) || !preg_match('/^\d+$/', $value) || intval($value) < $range[0] || intval($value) > $range[1]) {
                $errors[$key] = exmtrans('login.message.integer_range', exmtrans("login.{$key}"), $range[0], $range[1]);
                continue;
            }
            $integers[$key] = intval($value);
        }

        $booleans = [];
        foreach (static::getBruteForceBooleanSettings() as $key) {
            $booleans[$key] = $request->has($key) ? boolval($request->input($key)) : boolval(System::{$key}());
        }

        // the block time must not get shorter when blocked again
        if ($booleans['login_ip_block_progressive'] && count(array_intersect(['login_ip_block_minutes', 'login_ip_block_minutes_2', 'login_ip_block_minutes_3'], array_keys($errors))) == 0) {
            if (intval($integers['login_ip_block_minutes_2']) < intval($integers['login_ip_block_minutes'])) {
                $errors['login_ip_block_minutes_2'] = exmtrans('login.message.block_minutes_order', exmtrans('login.login_ip_block_minutes_2'), exmtrans('login.login_ip_block_minutes'));
            } elseif (intval($integers['login_ip_block_minutes_3']) < intval($integers['login_ip_block_minutes_2'])) {
                $errors['login_ip_block_minutes_3'] = exmtrans('login.message.block_minutes_order', exmtrans('login.login_ip_block_minutes_3'), exmtrans('login.login_ip_block_minutes_2'));
            }
        }

        $hasIps = $request->has('login_ip_block_allowed_ips');
        $ipsText = $request->input('login_ip_block_allowed_ips');
        $invalidIps = $hasIps ? IpAddressList::getInvalid($ipsText) : [];
        if (count($invalidIps) > 0) {
            $errors['login_ip_block_allowed_ips'] = exmtrans('login.message.country_ips_invalid', implode(', ', $invalidIps));
        }

        // CAPTCHA image is drawn by GD
        if ($booleans['login_captcha'] && !LoginCaptchaService::isAvailable()) {
            $errors['login_captcha'] = exmtrans('login.help.captcha_not_available');
        }

        if (count($errors) > 0) {
            admin_toastr(exmtrans('common.message.error_execute'), 'error');
            return back()->withInput()->withErrors($errors);
        }

        foreach ($integers as $key => $value) {
            System::{$key}($value);
        }
        foreach ($booleans as $key => $value) {
            System::{$key}($value);
        }
        if ($hasIps) {
            System::login_ip_block_allowed_ips(implode("\r\n", IpAddressList::parse($ipsText)));
        }

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('login_setting'));
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

        // who is required 2factor. NO (default): all users, as before. YES: the users of the role groups whose "require_2factor" is on
        $form->switchbool('login_2factor_target_role_group', exmtrans("2factor.login_2factor_target_role_group"))
            ->rules('boolean')
            ->help(exmtrans("2factor.help.login_2factor_target_role_group"))
            ->attribute(['data-filter' => json_encode(['key' => 'login_use_2factor', 'value' => '1']), 'data-filtertrigger' => true]);

        $form->number('login_2factor_setup_grace_minutes', exmtrans("2factor.login_2factor_setup_grace_minutes"))
            ->min(0)
            ->max(Auth2factorService::SETUP_GRACE_MINUTES_MAX)
            ->default(Auth2factorService::DEFAULT_SETUP_GRACE_MINUTES)
            ->rules('nullable|integer|min:0|max:' . Auth2factorService::SETUP_GRACE_MINUTES_MAX)
            ->help(exmtrans("2factor.help.login_2factor_setup_grace_minutes", Auth2factorService::SETUP_GRACE_MINUTES_MAX))
            ->attribute(['data-filter' => json_encode([
                ['key' => 'login_use_2factor', 'value' => '1'],
                ['key' => 'login_2factor_target_role_group', 'value' => '1'],
            ])]);

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
    // @phpstan-ignore-next-line
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

            // an empty grace minutes keeps the saved value. (empty must not become 0 = "set up at once")
            if (is_nullorempty(array_get($inputs, 'login_2factor_setup_grace_minutes'))) {
                unset($inputs['login_2factor_setup_grace_minutes']);
            }

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
    // @phpstan-ignore-next-line
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

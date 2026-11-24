<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\EditableUserInfoType;
use Exceedone\Exment\Validator as ExmentValidator;
use ExmentAdminCore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Carbon\Carbon;

/**
 * For login controller
 */
class AuthController extends \ExmentAdminCore\Admin\Controllers\AuthController
{
    use AuthTrait;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * Login page.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function getLoginExment(Request $request)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('exment::auth.login', $this->getLoginPageData());
    }

    /**
     * Handle a login request.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->throttle && $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            $this->sendLockoutResponse($request);
            return;
        }

        $this->loginValidator($request->all())->validate();

        $credentials = $request->only([$this->username(), 'password']);
        $credentials['islogin_from_provider'] = true;

        foreach (LoginSetting::getLdapSettings() as $login_setting) {
            if ($request->has("login_setting_{$login_setting->provider_name}")) {
                $credentials['login_setting'] =  $login_setting;
                $credentials['login_type'] =  $login_setting->login_type;
                return $this->executeLogin($request, $credentials, $login_setting);
            }
        }

        $credentials['login_type'] = LoginType::PURE;
        return $this->executeLogin($request, $credentials);
    }

    /**
     * Execute login(for Default, LDAP)
     *
     * @param Request $request
     * @param array $credentials
     * @param LoginSetting|null $login_setting
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    // @phpstan-ignore-next-line
    protected function executeLogin(Request $request, array $credentials, ?LoginSetting $login_setting = null)
    {
        $remember = boolval($request->get('remember', false));
        $error_url = admin_url('auth/login');

        try {
            if ($this->guard()->attempt($credentials, $remember)) {
                // check change password first time.
                if ($this->firstChangePassword($credentials['login_type'])) {
                    session([Define::SYSTEM_KEY_SESSION_FIRST_CHANGE_PASSWORD => true]);
                    return redirect(admin_url('auth/change'));
                }

                // check password limit.
                if (!$this->checkPasswordLimit($credentials['login_type'])) {
                    session([Define::SYSTEM_KEY_SESSION_PASSWORD_LIMIT => true]);
                    return redirect(admin_url('auth/change'));
                }

                $this->postVerifyEmail2factor();
                // @phpstan-ignore-next-line
                return $this->sendLoginResponse($request);
            }

            // If the login attempt was unsuccessful we will increment the number of attempts
            // to login and redirect the user back to the login form. Of course, when this
            // user surpasses their maximum number of attempts they will get locked out.
            $this->incrementLoginAttempts($request);

            return back()->withInput(get_flash_input())->withErrors([
                $this->username() => $this->getFailedLoginMessage(),
            ]);
        }
        // Sso exception
        catch (SsoLoginErrorException $ex) {
            \Illuminate\Support\Facades\Log::error($ex);
            return redirect($error_url)->withInput(get_flash_input())->withErrors(
                [$this->username() => $ex->getSsoErrorMessage()]
            );
        } catch (\Exception $ex) {
            \Illuminate\Support\Facades\Log::error($ex);
            return redirect($error_url)->withInput(get_flash_input())->withErrors(
                [$this->username() => exmtrans('login.sso_provider_error')]
            );
        }
    }


    /**
     * Check change password first time.
     *
     * @return bool if true, change password for first time. If false, continue.
     */
    // @phpstan-ignore-next-line
    protected function firstChangePassword($login_type)
    {
        if ($login_type != LoginType::PURE) {
            return false;
        }

        if (is_null($user = \Exceedone\Exment\Facades\ExmentFacade::user())) {
            return false;
        }

        return boolval(array_get($user, 'password_reset_flg'));
    }


    /**
     * Check password limit.
     *
     * @return bool if true, check password is OK. If false, user has to change password.
     */
    // @phpstan-ignore-next-line
    protected function checkPasswordLimit($login_type)
    {
        if ($login_type != LoginType::PURE) {
            return true;
        }

        // not use password policy and expiration days, go next
        if (empty($expiration_days = System::password_expiration_days())) {
            return true;
        }

        if (is_null($user = \Exceedone\Exment\Facades\ExmentFacade::user())) {
            return true;
        }

        // get password latest history
        $last_history = PasswordHistory::where('login_user_id', $user->id)
            ->orderby('created_at', 'desc')->first();

        if (is_null($last_history)) {
            return true;
        }

        // calc diff days
        $diff_days = (int) $last_history->created_at->diffInDays(Carbon::now(), true);
        if ($diff_days > $expiration_days) {
            return false;
        }

        return true;
    }

    /**
     * User logout.
     *
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse|\Illuminate\Support\Facades\Redirect
     */
    public function getLogout(Request $request)
    {
        $login_user = \Exceedone\Exment\Facades\ExmentFacade::user();
        $this->guard()->logout();

        // get option before clear session
        $options = [
            Define::SYSTEM_KEY_SESSION_SAML_SESSION => session(Define::SYSTEM_KEY_SESSION_SAML_SESSION),
        ];

        $request->session()->invalidate();
        // the login page then clears what this browser kept for the user (auth/layout)
        $request->session()->flash('exment_logged_out', true);

        if (isset($login_user) && $login_user->login_type != LoginType::PURE) {
            return $this->logoutSso($request, $login_user, $options);
        }

        return redirect(\Illuminate\Support\Facades\URL::route('exment.login'));
    }

    // @phpstan-ignore-next-line
    protected function postVerifyEmail2factor()
    {
        if (!boolval(config('exment.login_use_2factor', false)) || !boolval(System::login_use_2factor())) {
            return;
        }

        $auth2factor = Auth2factorService::getProvider();
        $auth2factor->insertVerify();
    }

    /**
     * file delete auth.
     */
    // @phpstan-ignore-next-line
    public function filedelete(Request $request)
    {
        $loginUser = \Exceedone\Exment\Facades\ExmentFacade::user();

        ExmentFile::deleteFileInfo($loginUser->avatar);
        $loginUser->avatar = null;
        $loginUser->save();

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        $login_user = LoginUser::class;
        return $login_user::form(function (Form $form) {
            // $form->display('base_user.value.user_code', exmtrans('user.user_code'));
            // $form->display('base_user.value.email', exmtrans('user.email'));

            // $form->text('base_user.value.user_name', exmtrans('user.user_name'));

            $user_table = CustomTable::getEloquent(SystemTableName::USER);
            foreach ($user_table->custom_columns as $custom_column) {
                $editable_userinfo = $custom_column->getOption('editable_userinfo');
                if (EditableUserInfoType::showSettingForm($editable_userinfo)) {
                    $column_item = $custom_column->column_item;
                    if (is_null($column_item)) {
                        continue;
                    }
                    if ($editable_userinfo == EditableUserInfoType::VIEW) {
                        $column_item->setFormColumnOptions(['view_only' => true]);
                    }
                    $field = $column_item
                        ->setCustomValue(\Exceedone\Exment\Facades\ExmentFacade::user()->base_user)
                        ->getAdminField(null, 'base_user.value.');

                    $form->pushField($field);
                }
            }

            $fileOption = array_merge(
                Define::FILE_OPTION(),
                [
                    'showPreview' => true,
                    'deleteUrl' => admin_urls('auth', 'setting', 'filedelete'),
                    'deleteExtraData'      => [
                        '_token'           => csrf_token(),
                        '_method'          => 'PUT',
                        'delete_flg'       => 'avatar',
                    ]
                ]
            );

            $form->image('avatar', exmtrans('user.avatar'))
                ->move('avatar')
                ->attribute(['accept' => "image/*"])
                ->options($fileOption)
                ->removable()
                ->help(array_get($fileOption, 'maxFileSizeHelp'))
                ->name(function ($file, $field) {
                    $exmentfile = ExmentFile::saveFileInfo(FileType::AVATAR, $field->getDirectory(), [
                        'filename' => $file->getClientOriginalName(),
                    ]);
                    return $exmentfile->local_filename;
                });

            // demo mode: the published password cannot be changed
            if (\Exceedone\Exment\Facades\ExmentFacade::user()->login_type == LoginType::PURE && !DemoMode::enabled()) {
                $form->password('current_password', exmtrans('user.current_password'))->rules(['required_with:password', new ExmentValidator\CurrentPasswordRule()])->help(exmtrans('user.help.change_only'));
                $form->password('password', exmtrans('user.new_password'))->rules(get_password_rule(false, \Exceedone\Exment\Facades\ExmentFacade::user()))->help(exmtrans('user.help.change_only') . \Exceedone\Exment\Facades\ExmentFacade::get_password_help());
                $form->password('password_confirmation', exmtrans('user.new_password_confirmation'));
            }

            
            $form->validatorSavingCallback(function ($input, $message, $form) {
                if (static::currentPasswordVerificationFails(\Exceedone\Exment\Facades\ExmentFacade::user(), $input)) {
                    $message->add('current_password', exmtrans('validation.current_password'));
                }
            });

            // show 2factor setting if use
            if (boolval(config('exment.login_use_2factor', false)) && boolval(System::login_use_2factor())) {
                $login_2factor_provider = \Exceedone\Exment\Facades\ExmentFacade::user()->getSettingValue(
                    implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
                    System::login_2factor_provider() ?? Login2FactorProviderType::EMAIL
                );

                $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider_user"))
                    ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
                    ->disableClear()
                    ->default($login_2factor_provider)
                    ->help(exmtrans("2factor.help.login_2factor_provider_user"));
            }

            // the language and the time zone of the user (ISS02835)
            $form->select('user_locale', exmtrans('user.locale'))
                ->options(UserLocale::allowedOptions())
                ->default(\Exceedone\Exment\Facades\ExmentFacade::user()->getSettingValue(UserLocale::SETTING_KEY))
                ->placeholder(exmtrans('user.locale_auto'))
                ->help(exmtrans('user.help.locale'));
            $form->select('user_timezone', exmtrans('user.timezone'))
                ->options(UserTimezone::options())
                ->default(\Exceedone\Exment\Facades\ExmentFacade::user()->getSettingValue(UserTimezone::SETTING_KEY))
                ->placeholder(exmtrans('user.timezone_site', ['timezone' => UserTimezone::site()]))
                ->help(exmtrans('user.help.timezone'));

            $form->setAction(admin_url('auth/setting'));
            $form->ignore(['password_confirmation', 'current_password', 'login_2factor_provider', 'user_locale', 'user_timezone']);
            $form->tools(function (Form\Tools $tools) {
                $tools->disableDelete();
                // the API keys of the user, when the administrators allow it (ISS00437)
                if (\Exceedone\Exment\Services\Features::allowed(\Exceedone\Exment\Services\Features::API_KEYS)) {
                    $tools->append('<a class="btn btn-sm btn-default" href="' . esc_html(admin_url('auth/api_keys')) . '"><i class="fa fa-key"></i> ' . esc_html(exmtrans('api_key.header')) . '</a>');
                }
            });

            $form->saving(function (Form $form) {
                // if not contains $form->password, return
                $form_password = $form->password;
                if (!isset($form_password)) {
                    $form->password = $form->model()->password;
                } elseif ($form_password && $form->model()->password != $form_password) {
                    $form->password = $form_password;
                }

                \Exceedone\Exment\Facades\ExmentFacade::user()->setSettingValue(
                    implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
                    request()->get('login_2factor_provider')
                );

                static::saveLocaleTimezone(request()->get('user_locale'), request()->get('user_timezone'));
            });

            $form->saved(function ($form) {
                admin_toastr(trans('admin.update_succeeded'));

                return redirect(admin_url('auth/setting'));
            });
        });
    }

    /**
     * Save the language and the time zone of the login user (empty: automatic / the time zone of the site).
     * A language or a time zone that is not allowed is not saved.
     */
    protected static function saveLocaleTimezone(?string $locale, ?string $timezone): void
    {
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        if (is_null($user)) {
            return;
        }

        $locale = UserLocale::match($locale);
        $user->setSettingValue(UserLocale::SETTING_KEY, $locale);
        if (UserTimezone::isValid($timezone) && $timezone !== UserTimezone::site()) {
            $user->setSettingValue(UserTimezone::SETTING_KEY, $timezone);
        } else {
            $user->setSettingValue(UserTimezone::SETTING_KEY, null);
        }
        UserLocale::clearCache();

        // the login page of this browser in the same language
        static::queueLocaleCookie($locale);
    }

    /**
     * The language chosen from the user menu (top right).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postLocale(Request $request)
    {
        $locale = UserLocale::match($request->get('locale'));
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        if (!is_null($user) && !DemoMode::enabled()) {
            $user->setSettingValue(UserLocale::SETTING_KEY, $locale);
            UserLocale::clearCache();
        }
        static::queueLocaleCookie($locale);

        return redirect(static::safeBackUrl($request));
    }

    /**
     * The language chosen on the login page (before the login: a cookie of the browser).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postLoginLocale(Request $request)
    {
        static::queueLocaleCookie(UserLocale::match($request->get('locale')));

        return redirect(admin_url('auth/login'));
    }

    protected static function queueLocaleCookie(?string $locale): void
    {
        if (is_null($locale)) {
            \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::forget(UserLocale::COOKIE_NAME));
            return;
        }
        // one year
        \Illuminate\Support\Facades\Cookie::queue(UserLocale::COOKIE_NAME, $locale, 60 * 24 * 365);
    }

    /**
     * Back to the page of the site the user was on (not to another site).
     */
    protected static function safeBackUrl(Request $request): string
    {
        $back = url()->previous();
        $base = rtrim(admin_url(), '/');
        if ($back === $base || strpos($back, $base . '/') === 0 || strpos($back, $base . '?') === 0) {
            return $back;
        }
        return $base;
    }

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable|\Exceedone\Exment\Model\LoginUser|null $login_user
     * @param array<string, mixed> $input
     * @return bool true => block the update (add a validation error)
     */
    protected static function currentPasswordVerificationFails($login_user, array $input): bool
    {
        // Only local (PURE) logins own/enforce a current password here; SSO/LDAP are out of scope.
        if (is_null($login_user) || $login_user->login_type != LoginType::PURE) {
            return false;
        }

        $new = array_get($input, 'password');
        if ($new === null || $new === '') {
            return false;
        }

        $current = array_get($input, 'current_password');
        if (!filled($current)) {
            return true;
        }

        // Present but not matching the stored credential => block.
        return !LoginUserProvider::ValidateCredential($login_user, ['password' => $current]);
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        if (Lang::has('exment::exment.error.login_failed')) {
            return exmtrans('error.login_failed');
        }
        return parent::getFailedLoginMessage();
    }
}

<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorLockService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorRecoveryCodeService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginCaptchaService;
use Exceedone\Exment\Services\Login\LoginFailureService;
use Exceedone\Exment\Services\Login\LoginIpBlockService;
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
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Widgets\Box;
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

        $data = $this->getLoginPageData();
        // CAPTCHA after failed logins
        $data['captcha_required'] = app(LoginCaptchaService::class)->isRequired($request);
        // the IP address blocked by failed logins: tell it before the user posts the form
        $block = app(LoginIpBlockService::class)->getActiveBlock($request->ip());
        $data['ip_block_message'] = is_null($block) ? null : LoginIpBlockService::getBlockedMessage($block);

        return view('exment::auth.login', $data);
    }

    /**
     * Image of CAPTCHA. A new code each time. (The code is kept in the session)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getCaptcha(Request $request)
    {
        if (!LoginCaptchaService::isAvailable()) {
            abort(404);
        }

        return response(app(LoginCaptchaService::class)->createImage($request), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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

        $ldap_setting = null;
        foreach (LoginSetting::getLdapSettings() as $login_setting) {
            if ($request->has("login_setting_{$login_setting->provider_name}")) {
                $ldap_setting = $login_setting;
                break;
            }
        }

        // CAPTCHA after failed logins: checked before the password.
        if (!is_null($response = $this->checkCaptcha($request, $ldap_setting))) {
            return $response;
        }

        if (!is_null($ldap_setting)) {
            $credentials['login_setting'] =  $ldap_setting;
            $credentials['login_type'] =  $ldap_setting->login_type;
            return $this->executeLogin($request, $credentials, $ldap_setting);
        }

        $credentials['login_type'] = LoginType::PURE;
        return $this->executeLogin($request, $credentials);
    }

    /**
     * Check CAPTCHA, if required. A wrong CAPTCHA is a failed login of the IP address. (The password is not checked)
     *
     * @param Request $request
     * @param LoginSetting|null $ldap_setting
     * @return \Illuminate\Http\RedirectResponse|null null: passed, or not required.
     */
    protected function checkCaptcha(Request $request, ?LoginSetting $ldap_setting)
    {
        $captcha = app(LoginCaptchaService::class);
        if (!$captcha->isRequired($request, true)) {
            return null;
        }

        // shown on the form from now on, even if the request below passes
        $captcha->markRequired($request);
        if ($captcha->verify($request)) {
            return null;
        }

        $block = app(LoginFailureService::class)->handleLoginFailure(
            $request,
            LoginFailure::REASON_CAPTCHA,
            $ldap_setting ? $ldap_setting->login_type : LoginType::PURE,
            null,
            $ldap_setting ? $ldap_setting->provider_name : null
        );
        if (!is_null($block)) {
            return LoginIpBlockService::getBlockedResponse($request, $block);
        }

        // keep the entered ID only. (never flash the password)
        return back()->withInput($request->only([$this->username(), 'remember']))->withErrors([
            LoginCaptchaService::INPUT_NAME => exmtrans('login.message.captcha_failed'),
        ]);
    }

    /**
     * Record the failed login (wrong ID or password), then block the IP address if it failed too many times.
     *
     * @param Request $request
     * @param array<string, mixed> $credentials
     * @param LoginSetting|null $login_setting
     * @return \Illuminate\Http\RedirectResponse|null the response of the block, if the IP address is blocked now.
     */
    protected function handleLoginFailure(Request $request, array $credentials, ?LoginSetting $login_setting)
    {
        // the user of the entered ID, if exists. (The account is counted whichever ID is entered: user code or e-mail)
        $guard = $this->guard();
        $attempted = method_exists($guard, 'getLastAttempted') ? $guard->getLastAttempted() : null;
        $login_user = $attempted instanceof LoginUser ? $attempted : null;

        $block = app(LoginFailureService::class)->handleLoginFailure(
            $request,
            LoginFailure::REASON_PASSWORD,
            array_get($credentials, 'login_type'),
            $login_user,
            $login_setting ? $login_setting->provider_name : null
        );
        if (!is_null($block)) {
            return LoginIpBlockService::getBlockedResponse($request, $block);
        }

        app(LoginCaptchaService::class)->requireIfNeeded($request, $login_user);
        return null;
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
                // logged in: CAPTCHA is not shown any more in this session
                app(LoginCaptchaService::class)->clear($request);

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

            // protection against brute-force attacks: the IP address may be blocked now
            if (!is_null($response = $this->handleLoginFailure($request, $credentials, $login_setting))) {
                return $response;
            }

            return back()->withInput()->withErrors([
                $this->username() => $this->getFailedLoginMessage(),
            ]);
        }
        // Sso exception
        catch (SsoLoginErrorException $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
                [$this->username() => $ex->getSsoErrorMessage()]
            );
        } catch (\Exception $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
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

        if (is_null($user = \Exment::user())) {
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

        if (is_null($user = \Exment::user())) {
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
        $login_user = \Exment::user();
        $this->guard()->logout();

        // get option before clear session
        $options = [
            Define::SYSTEM_KEY_SESSION_SAML_SESSION => session(Define::SYSTEM_KEY_SESSION_SAML_SESSION),
        ];

        $request->session()->invalidate();

        if (isset($login_user) && $login_user->login_type != LoginType::PURE) {
            return $this->logoutSso($request, $login_user, $options);
        }

        return redirect(\URL::route('exment.login'));
    }

    // @phpstan-ignore-next-line
    protected function postVerifyEmail2factor()
    {
        // not use 2factor, or the login user is not required 2factor (specified by role group)
        $login_user = \Exment::user();
        if (!Auth2factorService::isRequiredFor($login_user)) {
            return;
        }

        // locked by too many wrong codes: no new code is sent. (The page of 2factor authentication logs out the user)
        if ($login_user instanceof LoginUser && Auth2factorLockService::isLocked($login_user)) {
            return;
        }

        $auth2factor = Auth2factorService::getProvider();
        $auth2factor->insertVerify();
    }

    /**
     * Recovery codes of 2factor: show the remaining count, and confirm issuing new codes.
     *
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function getRecoveryCodesSetting(Content $content)
    {
        $login_user = \Exment::user();
        if (!Auth2factorService::canUseRecoveryCodes($login_user)) {
            return redirect(admin_url('auth/setting'));
        }

        $box = new Box(exmtrans('2factor.recovery_codes'), view('exment::auth.2factor.2factor-recovery-codes-setting', [
            'has_codes' => Auth2factorRecoveryCodeService::hasCodes($login_user),
            'remaining' => Auth2factorRecoveryCodeService::getRemainingCount($login_user),
            'total' => Auth2factorRecoveryCodeService::CODE_COUNT,
            'action' => admin_urls('auth', 'setting', 'recovery-codes'),
            'back_url' => admin_url('auth/setting'),
        ])->render());

        return $content
            ->title(trans('admin.user_setting'))
            ->body($box);
    }

    /**
     * Issue new recovery codes of 2factor. The old codes are invalidated, and the new codes are shown once.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function postRecoveryCodesSetting()
    {
        $login_user = \Exment::user();
        // a user who has not set up 2factor must not get recovery codes (they would replace the setup)
        if (!Auth2factorService::canUseRecoveryCodes($login_user)) {
            return redirect(admin_url('auth/setting'));
        }

        $codes = Auth2factorRecoveryCodeService::generate($login_user);
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR_RECOVERY_CODES => $codes]);

        return redirect(admin_urls('auth-2factor', 'recovery-codes'));
    }

    /**
     * file delete auth.
     */
    // @phpstan-ignore-next-line
    public function filedelete(Request $request)
    {
        $loginUser = \Exment::user();

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
                        ->setCustomValue(\Exment::user()->base_user)
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

            if (\Exment::user()->login_type == LoginType::PURE) {
                $form->password('current_password', exmtrans('user.current_password'))->rules(['required_with:password', new ExmentValidator\CurrentPasswordRule()])->help(exmtrans('user.help.change_only'));
                $form->password('password', exmtrans('user.new_password'))->rules(get_password_rule(false, \Exment::user()))->help(exmtrans('user.help.change_only') . \Exment::get_password_help());
                $form->password('password_confirmation', exmtrans('user.new_password_confirmation'));
            }

            
            $form->validatorSavingCallback(function ($input, $message, $form) {
                if (static::currentPasswordVerificationFails(\Exment::user(), $input)) {
                    $message->add('current_password', exmtrans('validation.current_password'));
                }
            });

            // show 2factor setting if use
            if (Auth2factorService::isUse2factor()) {
                $login_2factor_provider = \Exment::user()->getSettingValue(
                    implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
                    System::login_2factor_provider() ?? Login2FactorProviderType::EMAIL
                );

                $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider_user"))
                    ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
                    ->disableClear()
                    ->default($login_2factor_provider)
                    ->help(exmtrans("2factor.help.login_2factor_provider_user"));

                // recovery codes: remaining count and the link to issue new codes (confirmed on its own page).
                // only for a user who has set up 2factor: codes must never replace the setup.
                if (Auth2factorService::canUseRecoveryCodes(\Exment::user())) {
                    $remaining = Auth2factorRecoveryCodeService::getRemainingCount(\Exment::user());
                    $html = '<span>' . esc_html(exmtrans('2factor.message.recovery_codes_remaining', $remaining, Auth2factorRecoveryCodeService::CODE_COUNT)) . '</span>'
                        . ' <a href="' . esc_html(admin_urls('auth', 'setting', 'recovery-codes')) . '" class="btn btn-sm btn-default" style="margin-left:1em;">'
                        . '<i class="fa fa-refresh"></i> ' . esc_html(exmtrans('2factor.regenerate_recovery_codes')) . '</a>';
                    $form->html($html, exmtrans('2factor.recovery_codes'))
                        ->help(exmtrans('2factor.help.recovery_codes_user'));
                }
            }

            $form->setAction(admin_url('auth/setting'));
            $form->ignore(['password_confirmation', 'current_password', 'login_2factor_provider']);
            $form->tools(function (Form\Tools $tools) {
                $tools->disableDelete();
            });

            $form->saving(function (Form $form) {
                // if not contains $form->password, return
                $form_password = $form->password;
                if (!isset($form_password)) {
                    $form->password = $form->model()->password;
                } elseif ($form_password && $form->model()->password != $form_password) {
                    $form->password = $form_password;
                }

                \Exment::user()->setSettingValue(
                    implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
                    request()->get('login_2factor_provider')
                );
            });

            $form->saved(function ($form) {
                admin_toastr(trans('admin.update_succeeded'));

                return redirect(admin_url('auth/setting'));
            });
        });
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

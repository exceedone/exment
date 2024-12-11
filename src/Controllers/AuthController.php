<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\EditableUserInfoType;
use Exceedone\Exment\Validator as ExmentValidator;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Carbon\Carbon;

/**
 * For login controller
 */
class AuthController extends \Encore\Admin\Controllers\AuthController
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
                return $this->sendLoginResponse($request);
            }

            // If the login attempt was unsuccessful we will increment the number of attempts
            // to login and redirect the user back to the login form. Of course, when this
            // user surpasses their maximum number of attempts they will get locked out.
            $this->incrementLoginAttempts($request);

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
        $diff_days = $last_history->created_at->diffInDays(Carbon::now());

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

            // show 2factor setting if use
            if (boolval(config('exment.login_use_2factor', false)) && boolval(System::login_use_2factor())) {
                $login_2factor_provider = \Exment::user()->getSettingValue(
                    implode(".", [UserSetting::USER_SETTING, 'login_2factor_provider']),
                    System::login_2factor_provider() ?? Login2FactorProviderType::EMAIL
                );

                $form->select('login_2factor_provider', exmtrans("2factor.login_2factor_provider_user"))
                    ->options(Login2FactorProviderType::transKeyArray('2factor.2factor_provider_options'))
                    ->disableClear()
                    ->default($login_2factor_provider)
                    ->help(exmtrans("2factor.help.login_2factor_provider_user"));
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
                    /** @phpstan-ignore-next-line fix laravel-admin documentation */
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

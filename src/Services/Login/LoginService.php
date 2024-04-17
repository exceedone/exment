<?php

namespace Exceedone\Exment\Services\Login;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\Login\OAuth\OAuthUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * LoginService
 */
class LoginService
{
    /**
     * Reset password. contains send password.
     *
     * @param LoginUser|CustomValue $user CustomValue(user) or login user
     * @param array $options
     * @return \Illuminate\Http\RedirectResponse|void
     * @throws \Exception
     */
    public static function resetPassword($user, array $options = [])
    {
        $options = array_merge([
            'send_password' => false,  // whether sending password.
            'password_reset_flg' => false,  // whether reset password flg first login.
            'password' => null, // password string.
        ], $options);

        $send_password = $options['send_password'];
        $password_reset_flg = $options['password_reset_flg'];
        $password = $options['password'];

        // get user
        if ($user instanceof LoginUser) {
            $login_user = $user;
        } elseif ($user instanceof CustomValue) {
            $login_user = $user->login_user;
        } else {
            throw new \Exception('Please input LoginUser or CustomValue.');
        }

        try {
            $login_user->password_reset_flg = System::first_change_password() || boolval($password_reset_flg);

            if (is_nullorempty($password)) {
                $password = make_password();
            }
            $login_user->password = $password;

            // mailsend
            if (boolval($send_password)) {
                try {
                    $login_user->sendPassword($password);
                }
                // throw mailsend Exception
                catch (TransportExceptionInterface $ex) {
                    \Log::error($ex);
                    admin_error(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                    return back()->withInput();
                }
            }
            $login_user->save();
        } catch (\Exception $ex) {
            throw $ex;
        }
    }


    public static function setToken()
    {
        // get custom login user
        $custom_login_user = System::requestSession(Define::SYSTEM_KEY_SESSION_CUSTOM_LOGIN_USER);
        if (is_nullorempty($custom_login_user) || $custom_login_user->login_type != LoginType::OAUTH) {
            return;
        }

        // set session access key
        session([Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN => [
            'access_token' => $custom_login_user->token,
            'refresh_token' => $custom_login_user->refreshToken,
            'provider' => $custom_login_user->provider_name,
            'expiresIn' =>  $custom_login_user->expiresIn,
        ]]);
    }

    /**
     * Get access and refresh token
     *
     * @return array access_token, refresh_token, provider
     */
    public static function getToken()
    {
        $session = session(Define::SYSTEM_KEY_SESSION_PROVIDER_TOKEN);
        return [
            'access_token' => array_get($session, 'access_token'),
            'refresh_token' => array_get($session, 'refresh_token'),
            'provider' => array_get($session, 'provider')
        ];
    }

    /**
     * Get access token
     *
     * @return string|null
     */
    public static function getAccessToken()
    {
        return static::getToken()['access_token'];
    }

    /**
     * Get refresh token
     *
     * @return string|null
     */
    public static function getRefreshToken()
    {
        return static::getToken()['refresh_token'];
    }

    /**
     * Get custom login validator for synced user.
     *
     * @param CustomLoginUserBase $custom_login_user
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public static function validateCustomLoginSync(CustomLoginUserBase $custom_login_user)
    {
        // get target user
        $exment_user = static::getExmentUser($custom_login_user, false);
        if ($exment_user === false) {
            $exment_user = null;
        }

        $data = $custom_login_user->mapping_values;
        $custom_table = CustomTable::getEloquent(SystemTableName::USER);
        $dbTableName = getDBTableName($custom_table);
        $rules = $custom_table->getValidateRules($data, $exment_user);
        // add unique rules
        foreach ($custom_table->custom_columns as $custom_column) {
            if (boolval($custom_column->unique)) {
                $column_name = $custom_column->column_name;
                $unique_rule = Rule::unique($dbTableName, "value->$column_name");
                if ($exment_user) {
                    $unique_rule = $unique_rule->ignore($exment_user->id);
                }
                $rules = array_merge_recursive($rules, [$column_name => [$unique_rule]]);
            }
        }

        $rules = static::removeInitRule($custom_login_user, $exment_user, $rules);

        return \Validator::make($data, $rules);
    }

    /**
     * Validate user unique columns.
     *
     * @param CustomLoginUserBase $custom_login_user
     * @return array
     */
    public static function validateUniques(CustomLoginUserBase $custom_login_user)
    {
        // get target user
        $exment_user = static::getExmentUser($custom_login_user, false);
        if ($exment_user === false) {
            $exment_user = null;
        }

        if (!static::needCheck($custom_login_user, $exment_user)) {
            return [];
        }

        $data = $custom_login_user->mapping_values;
        $errors = CustomTable::getEloquent(SystemTableName::USER)->validatorUniques($data, $exment_user, [
            'addValue' => false
        ]);

        $res=[];
        array_walk($errors, function ($x) use (&$res) {
            $res=array_merge($res, $x);
        });

        return $res;
    }

    /**
     * Remove "unique" and "init_flg" rule
     *
     * @param CustomLoginUserBase $custom_login_user
     * @param CustomValue|null $exment_user
     * @param array $rules
     * @return array updated rules
     */
    protected static function removeInitRule(CustomLoginUserBase $custom_login_user, ?CustomValue $exment_user, array $rules)
    {
        if (static::needCheck($custom_login_user, $exment_user)) {
            return $rules;
        }

        // In other cases, no validation will be performed and an empty array will be returned.
        return [];
    }

    /**
     * check if need validate user data.
     *
     * @param CustomLoginUserBase $custom_login_user
     * @param CustomValue|null $exment_user
     * @return boolean true:need validate
     */
    protected static function needCheck(CustomLoginUserBase $custom_login_user, ?CustomValue $exment_user)
    {
        // remove unique, if not update and create. Because only use key for login
        $login_setting = $custom_login_user->login_setting;

        // If has exment user and update user info, return rules(all validate)
        if (isset($exment_user) && boolval($login_setting->getOption('update_user_info'))) {
            return true;
        }
        // If not has exment user and sso_jit, return rules(all validate)
        elseif (!isset($exment_user) && boolval($login_setting->getOption('sso_jit'))) {
            return true;
        }
        return false;
    }

    /**
     * Get login test result.
     *
     * @param bool $result
     * @param array $messages
     * @param array $adminMessages
     * @param CustomLoginUserBase $custom_login_user
     * @return array
     */
    public static function getLoginResult($result, $messages, $adminMessages = null, ?CustomLoginUserBase $custom_login_user = null)
    {
        if (is_nullorempty($adminMessages)) {
            $adminMessages = $messages;
        }

        $message = static::convertErrorMessage($result, $messages, $custom_login_user);
        $adminMessage = static::convertErrorMessage($result, $adminMessages, $custom_login_user);

        return [$result, $message, $adminMessage, $custom_login_user];
    }

    protected static function convertErrorMessage($result, $messages, ?CustomLoginUserBase $custom_login_user = null)
    {
        $message = [];

        $message[] = $result === true ? exmtrans('common.message.success_execute') : exmtrans('common.message.error_execute');

        if (is_string($messages)) {
            $message = array_merge($message, (array)$messages);
        } elseif (is_array($messages)) {
            $message = array_merge($message, $messages);
        } elseif ($messages instanceof \Illuminate\Support\MessageBag) {
            $message = array_merge($message, collect($messages->messages())->map(function ($m, $key) use ($custom_login_user) {
                $inputValue = isset($custom_login_user) ? array_get($custom_login_user->mapping_values, $key) : null;
                return implode(" ", $m) . (isset($inputValue) ? " : $inputValue" : '');
            })->toArray());
        }

        if ($result === true && $custom_login_user) {
            $keys = [
                'user_code',
                'user_name',
                'email',
            ];

            foreach ($keys as $key) {
                $message[] = exmtrans("user.$key") . ' : ' . $custom_login_user->mapping_values[$key];
            }
        }

        return implode("\r\n", $message);
    }

    /**
     * Get test form for sso
     *
     * @param LoginSetting $login_setting
     * @return ModalForm
     */
    public static function getTestFormSso(LoginSetting $login_setting)
    {
        $form = new ModalForm();
        $form->action(route('exment.logintest_form', ['id' => $login_setting->id]));
        $form->disableReset();
        $form->disableSubmit();

        $form->descriptionHtml(exmtrans('login.message.login_test_description'));

        $form->url('login_test_redirect', exmtrans('login.login_test_redirect'))
        ->readonly()
        ->attribute(['copyScript' => 1])
        ->default($login_setting->exment_callback_url_test)
        ->help(exmtrans('login.help.login_test_sso', ['login_type' => LoginType::getEnum($login_setting->login_type)->transKey('login.login_type_options')]));


        // get message from session
        $message = session()->pull(Define::SYSTEM_KEY_SESSION_SSO_TEST_MESSAGE);
        $form->textarea('resultarea', exmtrans('common.execute_result'))
            ->attribute(['readonly' => true])
            ->default($message)
            ->rows(4)
        ;

        $url = route('exment.logintest_sso', ['id' => $login_setting->id]);
        $form->html("<a href='{$url}' data-nopjax data-modalclose='false' class='btn btn-primary click_disabled'>" . trans('admin.login') . "</a>");

        $form->setWidth(10, 2);


        return $form;
    }

    public static function appendActivateSwalButtonSso($tools, LoginSetting $login_setting)
    {
        if (!$login_setting->active_flg) {
            $tools->append(new Tools\SwalInputButton([
                'url' => route('exment.login_activate', ['id' => $login_setting->id]),
                'label' => exmtrans('common.activate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-success',
                'title' => exmtrans('common.activate'),
                'text' => exmtrans('login.help.activate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("login_setting", $login_setting->id, "edit"),
            ]));
        } else {
            $tools->append(new Tools\SwalInputButton([
                'url' => route('exment.login_deactivate', ['id' => $login_setting->id]),
                'label' => exmtrans('common.deactivate'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-default',
                'title' => exmtrans('common.deactivate'),
                'text' => exmtrans('login.help.deactivate'),
                'method' => 'post',
                'redirectUrl' => admin_urls("login_setting", $login_setting->id, "edit"),
            ]));
        }
    }

    /**
     * execute login. return $login_user.
     *
     * @param Request $request
     * @param CustomLoginUserBase $custom_login_user
     * @param \Exceedone\Exment\Auth\ProviderAvatar $socialiteProvider
     * @return LoginUser|null
     */
    public static function executeLogin(Request $request, CustomLoginUserBase $custom_login_user, $socialiteProvider = null): ?LoginUser
    {
        // if not accept domain, return error.
        if (!static::isAcceptFromDomain($custom_login_user)) {
            throw new SsoLoginErrorException(SsoLoginErrorType::NOT_ACCEPT_DOMAIN, exmtrans('login.not_accept_domain', [
                'domain' => $custom_login_user->domain(),
            ]));
        }

        // check exment user
        $exment_user = static::getExmentUser($custom_login_user);
        if ($exment_user === false) {
            // Check system setting jit
            $exment_user = static::createExmentUser($custom_login_user);
        }

        $login_user = static::getLoginUser($custom_login_user, $exment_user, $socialiteProvider);

        // Set custom_login_user to request session
        System::setRequestSession(Define::SYSTEM_KEY_SESSION_CUSTOM_LOGIN_USER, $custom_login_user);

        return $login_user;
    }

    /**
     * if not accept domain, return error.
     *
     * @param CustomLoginUserBase $custom_login_user
     * @return boolean
     */
    public static function isAcceptFromDomain(CustomLoginUserBase $custom_login_user)
    {
        //
        if (is_nullorempty($sso_accept_mail_domains = System::sso_accept_mail_domain())) {
            return true;
        }

        // check domain
        $email_domain = $custom_login_user->domain();
        $domain_result = false;
        foreach (explodeBreak($sso_accept_mail_domains) as $sso_accept_mail_domain) {
            if ($email_domain == $sso_accept_mail_domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * get exment user from users table
     *
     * @param CustomLoginUserBase $custom_login_user
     * @return CustomValue|null|false if false, not found user.
     */
    public static function getExmentUser(CustomLoginUserBase $custom_login_user, bool $isUpdate = true)
    {
        $exment_user = getModelName(SystemTableName::USER)::where("value->{$custom_login_user->mapping_user_column}", $custom_login_user->login_id)
            ->first();
        if (!isset($exment_user)) {
            return false;
        }

        // update user info
        if ($isUpdate && boolval($custom_login_user->login_setting->getOption('update_user_info'))) {
            // update only init_only is false
            $update_user_columns = static::getUserColumns()->filter(function ($column) {
                return !boolval($column->getOption('init_only'));
            });

            $values = $update_user_columns->mapWithKeys(function ($column) use ($custom_login_user) {
                return [$column->column_name => array_get($custom_login_user->mapping_values, $column->column_name)];
            });

            $exment_user->setValue($values);
            $exment_user->save();
        }

        return $exment_user;
    }

    /**
     * create exment user from users table
     *
     * @param CustomLoginUserBase $custom_login_user
     * @return ?CustomValue user model
     */
    public static function createExmentUser(CustomLoginUserBase $custom_login_user): ?CustomValue
    {
        if (!boolval($custom_login_user->login_setting->getOption('sso_jit'))) {
            throw new SsoLoginErrorException(SsoLoginErrorType::NOT_EXISTS_EXMENT_USER, exmtrans('login.noexists_user'));
        }

        $exment_user = null;
        \ExmentDB::transaction(function () use ($custom_login_user, &$exment_user) {
            $exment_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();

            $update_user_columns = static::getUserColumns();
            $values = $update_user_columns->mapWithKeys(function ($column) use ($custom_login_user) {
                return [$column->column_name => array_get($custom_login_user->mapping_values, $column->column_name)];
            });

            $exment_user->setValue($values);
            $exment_user->save();

            // Set roles
            if (!is_nullorempty($jit_rolegroups = $custom_login_user->login_setting->getOption('jit_rolegroups'))) {
                $jit_rolegroups = collect($jit_rolegroups)->map(function ($sso_rolegroup) use ($exment_user) {
                    return [
                        'role_group_id' => $sso_rolegroup,
                        'role_group_user_org_type' => SystemTableName::USER,
                        'role_group_target_id' => $exment_user->id,
                    ];
                })->toArray();

                \DB::table(SystemTableName::ROLE_GROUP_USER_ORGANIZATION)->insert($jit_rolegroups);
            }
        });

        return $exment_user;
    }


    /**
     * get login_user from login_users table
     *
     * @param CustomLoginUserBase $custom_login_user
     * @param CustomValue|LoginUser $exment_user
     * @param \Exceedone\Exment\Auth\ProviderAvatar $socialiteProvider
     * @return LoginUser
     */
    public static function getLoginUser(CustomLoginUserBase $custom_login_user, $exment_user, $socialiteProvider = null): LoginUser
    {
        $hasLoginUser = false;
        // get login_user
        $login_user = LoginUserProvider::findByCredential(
            [
                'target_column' => $custom_login_user->mapping_user_column,
                'username' => $custom_login_user->login_id,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]
        );

        // if don't has, create loginuser or match email
        /** @phpstan-ignore-next-line $hasLoginUser Negated boolean expression is always true. */
        if (!$hasLoginUser) {
            $login_user = LoginUser::firstOrNew([
                'base_user_id' => $exment_user->getUserId(),
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]);
            $login_user->base_user_id = $exment_user->getUserId();
            $login_user->login_provider = $custom_login_user->provider_name;
            $login_user->password = make_password(32);
        }

        // get avatar
        /** @phpstan-ignore-next-line $hasLoginUser Negated boolean expression is always true. */
        if (!$hasLoginUser || boolval($custom_login_user->login_setting->getOption('update_user_info'))) {
            $avatar  = static::getAvatar($custom_login_user, $socialiteProvider = null);
            if (isset($avatar)) {
                $login_user->avatar = $avatar;
            }
        }

        $login_user->save();
        return $login_user;
    }

    public static function getAvatar(CustomLoginUserBase $custom_login_user, $socialiteProvider = null)
    {
        try {
            // if socialiteProvider implements ProviderAvatar, call getAvatar
            if (isset($socialiteProvider) && is_subclass_of($socialiteProvider, \Exceedone\Exment\Auth\ProviderAvatar::class)) {
                /** @var OAuthUser $custom_login_user */
                $stream = $socialiteProvider->getAvatar($custom_login_user->token);
            }
            // if user obj has avatar, download avatar.
            elseif (isset($custom_login_user->avatar)) {
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', $custom_login_user->avatar, [
                    'http_errors' => false,
                ]);
                $stream = $response->getBody()->getContents();
            }
            // file upload.
            if (isset($stream) && isset($custom_login_user->id)) {
                $file = ExmentFile::put(FileType::AVATAR, path_join("avatar", $custom_login_user->id), $stream, [
                    'override' => true,
                ]);
                return $file->path;
            }
        } finally {
        }
        return null;
    }

    protected static function getUserColumns()
    {
        return CustomTable::getEloquent(SystemTableName::USER)->custom_columns_cache;
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return mixed
     */
    protected static function guard()
    {
        return \Auth::guard('admin');
    }
}

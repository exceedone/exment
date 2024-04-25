<?php

namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * LoginService
 */
class OAuthService implements LoginServiceInterface
{
    /**
     * Checking retrieveByCredential.
     * (1) Login using LDAP
     * (2) Get user info
     * (3) sync from exment column name
     * (4) Validation value
     * (5) get login_user info. If not exists, create user (if set setting).
     * return login_user
     * @param array $credentials
     * @return LoginUser|null
     * if null, not match ldap user. Showing wrong ID or password not match.
     *
     * @throws SsoLoginErrorException
     */
    public static function retrieveByCredential(array $credentials)
    {
        list($result, $message, $adminMessage, $custom_login_user) = static::loginCallback(request(), array_get($credentials, 'login_setting') ?? LoginSetting::getOAuthSetting(array_get($credentials, 'provider_name')));

        if ($result === true) {
            return LoginService::executeLogin(request(), $custom_login_user);
        }

        // if not exists, retun null
        if ($result == SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER) {
            return null;
        }

        // else, throw exception
        throw new SsoLoginErrorException($result, $message, $adminMessage);
    }


    /**
     * Validate Credential. Check password.
     *
     * @param Authenticatable $login_user
     * @param array $credentials
     * @return boolean
     */
    public static function validateCredential(Authenticatable $login_user, array $credentials)
    {
        // always true.
        return true;
    }


    public static function getTestForm(LoginSetting $login_setting)
    {
        return LoginService::getTestFormSso($login_setting);
    }

    public static function setOAuthForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::OAUTH)) {
            $form->descriptionHtml($errors[LoginType::OAUTH])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

            return;
        }

        if (!isset($login_setting)) {
            $form->select('oauth_provider_type', exmtrans('login.oauth_provider_type'))
                ->options(LoginProviderType::transKeyArray('login.oauth_provider_type_options'))
                ->required()
                ->attribute(['data-filtertrigger' => true,
                    'data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]]),

                    'data-changehtml' => json_encode([
                        [
                            'url' => admin_urls('login_setting', 'loginOptionHtml'),
                            'target' => '.form_dynamic_options',
                            'response' => '.form_dynamic_options_response',
                            'form_type' => 'option',
                        ],
                    ]),
                ]);

            $login_provider_caution = '<span class="red">' . exmtrans('login.message.oauth_provider_caution', [
                'url' => getManualUrl('sso'),
            ]) . '</span>';
            $form->descriptionHtml($login_provider_caution)
            ->attribute(['data-filter' => json_encode(['key' => 'options_provider_type', 'value' => [LoginProviderType::OTHER]])]);

            $form->text('oauth_provider_name', exmtrans('login.oauth_provider_name'))
                ->required()
                ->help(exmtrans('login.help.login_provider_name'))
                ->attribute([
                    'data-filtertrigger' => true,
                    'data-filter' => json_encode(['key' => 'options_oauth_provider_type', 'value' => [LoginProviderType::OTHER]]),
                    'data-changehtml' => json_encode([
                        [
                            'url' => admin_urls('login_setting', 'loginOptionHtml'),
                            'target' => '.form_dynamic_options',
                            'response' => '.form_dynamic_options_response',
                            'form_type' => 'option',
                        ],
                    ]),
                ]);
        } else {
            $form->display('oauth_provider_type_text', exmtrans('login.oauth_provider_type'))
                ->displayText(exmtrans('login.oauth_provider_type_options.' . $login_setting->getOption('oauth_provider_type')));
            $form->hidden('oauth_provider_type');

            $form->display('oauth_provider_name_text', exmtrans('login.oauth_provider_name'))
                ->displayText($login_setting->getOption('oauth_provider_name'));
            $form->hidden('oauth_provider_name');
        }

        $form->text('oauth_client_id', exmtrans('login.oauth_client_id'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('oauth_client_secret', exmtrans('login.oauth_client_secret'))
        ->required()
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        $form->text('oauth_scope', exmtrans('login.oauth_scope'))
        ->help(exmtrans('login.help.scope'))
        ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);

        if (isset($login_setting)) {
            if (boolval(config('exment.expart_mode', false))) {
                $form->url('oauth_redirect_url', exmtrans('login.redirect_url'))
                ->help(exmtrans('login.help.redirect_url') . exmtrans('login.help.redirect_url_default', ['url' => $login_setting->exment_callback_url_default]))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);
            } else {
                $form->display('oauth_redirect_url', exmtrans('login.redirect_url'))->default($login_setting->exment_callback_url);
            }
        }
    }

    /**
     * Execute login test
     *
     * @param Request $request
     * @param $login_setting
     * @return mixed
     */
    public static function loginTest(Request $request, $login_setting)
    {
        // provider check
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, true);
        return $socialiteProvider->redirect();
    }

    /**
     * Execute login for plugin clud
     *
     * @param Request $request
     */
    public static function loginPluginClud(Request $request, $login_setting, string $callbackUrl)
    {
        // provider get
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, false, $callbackUrl);

        return $socialiteProvider->redirect();
    }


    /**
     * Execute login callback
     *
     * @param Request $request
     * @param LoginSetting $login_setting
     * @return array $result(bool), $message(string), $adminMessage(string), $custom_login_user
     */
    public static function loginCallback(Request $request, $login_setting, $isTest = false)
    {
        $custom_login_user = null;
        $message = null;
        try {
            $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, $isTest);

            $custom_login_user = OAuthUser::with($login_setting->provider_name, $socialiteProvider->user(), true);

            /** @var ExmentCustomValidator $validator */
            $validator = LoginService::validateCustomLoginSync($custom_login_user);
            if ($validator->fails()) {
                return LoginService::getLoginResult(
                    SsoLoginErrorType::SYNC_VALIDATION_ERROR,
                    exmtrans('login.sso_provider_error_validate', ['errors' => implode(' ', $validator->getMessageStrings())]),
                    $validator->errors(),
                    $custom_login_user
                );
            } else {
                $errors = LoginService::validateUniques($custom_login_user);
                if (count($errors) > 0) {
                    return LoginService::getLoginResult(
                        SsoLoginErrorType::SYNC_VALIDATION_ERROR,
                        exmtrans('login.sso_provider_error_validate', ['errors' => implode(' ', $errors)]),
                        $errors,
                        $custom_login_user
                    );
                }
                return LoginService::getLoginResult(true, [], [], $custom_login_user);
            }
        } catch (\Exception $ex) {
            \Log::error($ex);

            return LoginService::getLoginResult(SsoLoginErrorType::UNDEFINED_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        } catch (\Throwable $ex) {
            \Log::error($ex);

            return LoginService::getLoginResult(SsoLoginErrorType::UNDEFINED_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        }
    }


    /**
     * Get auth for oauth.
     *
     * @return string|null
     */
    public static function getAccessTokenFromDB(LoginSetting $login_setting): ?string
    {
        // get access token by user setting
        $key = ("plugin_crud_oauth_access_token_{$login_setting->id}");
        $key_expires_at = ("plugin_crud_oauth_access_token_expires_at_{$login_setting->id}");
        $access_token = \Exment::user()->getSettingValue($key);
        $expires_at = \Exment::user()->getSettingValue($key_expires_at);
        if (!$access_token || !$expires_at) {
            return null;
        }

        // Check whether ex
        $expiresAt = \Carbon\Carbon::parse($expires_at);
        if ($expiresAt <= \Carbon\Carbon::now()->addMinutes(-1)) {
            return null;
        }

        return $access_token;
    }

    /**
     * Set database and callback auth for oauth.
     *
     * @param LoginSetting $login_setting
     * @param string|null $callbackUrl
     * @return string|null
     */
    public static function callbackAccessTokenToDB(LoginSetting $login_setting, ?string $callbackUrl): ?string
    {
        try {
            $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, false, $callbackUrl);
            // get user
            $user = $socialiteProvider->user();
            $token = $user->token;
            $expiresIn = $user->expiresIn;

            // get Expired at
            $now = \Carbon\Carbon::now();
            $expiresAt = $now->addSeconds($expiresIn);

            // get access token by user setting
            $key = ("plugin_crud_oauth_access_token_{$login_setting->id}");
            $key_expires_at = ("plugin_crud_oauth_access_token_expires_at_{$login_setting->id}");
            \Exment::user()->setSettingValue($key, $token);
            \Exment::user()->setSettingValue($key_expires_at, $expiresAt);
            return $token;
        } catch (\Exception $ex) {
            return null;
        }
    }


    /**
     * Clear auth for oauth.
     *
     * @return string|null
     */
    public static function clearAccessTokenFromDB(LoginSetting $login_setting)
    {
        // get access token by user setting
        $key = ("plugin_crud_oauth_access_token_{$login_setting->id}");
        $key_expires_at = ("plugin_crud_oauth_access_token_expires_at_{$login_setting->id}");
        \Exment::user()->forgetSettingValue($key);
        \Exment::user()->forgetSettingValue($key_expires_at);
    }


    public static function appendActivateSwalButton($tools, LoginSetting $login_setting)
    {
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
    }

    /**
     * Set custom config for login setting controller.
     *
     * @param $provider_name
     * @param \Encore\Admin\Form $form
     * @return void
     */
    public static function setLoginSettingForm($provider_name, $form)
    {
        if (is_nullorempty($provider_name)) {
            return;
        }

        // set dummy client id, secret, redirect
        config(["services.{$provider_name}" => [
            'client_id' => 'dummy',
            'client_secret' => 'dummy',
            'redirect' => 'https://foobar.com',
        ]]);

        try {
            $socialiteProvider = \Socialite::with($provider_name);

            // has instance of
            if (!is_nullorempty($socialiteProvider) && is_subclass_of($socialiteProvider, \Exceedone\Exment\Auth\ProviderLoginConfig::class)) {
                /** @phpstan-ignore-next-line */
                $form->exmheader(exmtrans('login.custom_setting'))->hr();

                $socialiteProvider->setLoginSettingForm($form);
            }
        } catch (\Exception $ex) {
            // if not found provider_name in Socialite, nothing.
        }
    }
}

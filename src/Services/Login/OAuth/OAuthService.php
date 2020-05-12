<?php
namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
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
     * @return ?LoginUser
     * if null, not match ldap user. Showing wrong ID or password not match.
     * 
     * @throws SsoLoginErrorException
     */
    public static function retrieveByCredential(array $credentials)
    {
        list($result, $message, $adminMessage, $custom_login_user) = static::loginCallback(request(), array_get($credentials, 'login_setting') ?? LoginSetting::getOAuthSetting(array_get($credentials, 'provider_name')));

        if($result === true){
            return LoginService::executeLogin(request(), $custom_login_user);
        }

        // if not exists, retun null
        if($result == SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER){
            return null;
        }

        // else, throw exception
        throw new SsoLoginErrorException($result, $message);
    }


    /**
     * Validate Credential. Check password.
     *
     * @param Authenticatable $login_user
     * @param array $credentials
     * @return void
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

        if (isset($login_setting)) {
            if(boolval(config('exment.expart_mode', false))){
                $form->url('oauth_redirect_url', exmtrans('login.redirect_url'))
                ->help(exmtrans('login.help.redirect_url') . exmtrans('login.help.redirect_url_default', ['url' => $login_setting->exment_callback_url_default]))
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);
            }
            else {
                $form->display('oauth_redirect_url', exmtrans('login.redirect_url'))->default($login_setting->exment_callback_url);
            }
        } 
    }

    
    /**
     * Execute login test
     *
     * @param Request $request
     * @return void
     */
    public static function loginTest(Request $request, $login_setting)
    {
        // provider check
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, true);
        return $socialiteProvider->redirect();
    }

    
    /**
     * Execute login callback
     *
     * @param Request $request
     * @return void
     */
    public static function loginCallback(Request $request, $login_setting, $isTest = false)
    {
        $custom_login_user = null;
        $message = null;
        try {
            $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, $isTest);

            $custom_login_user = OAuthUser::with($login_setting->provider_name, $socialiteProvider->user(), true);

            $validator = LoginService::validateCustomLoginSync($custom_login_user->mapping_values);
            if ($validator->fails()) {
                return LoginService::getLoginResult(SsoLoginErrorType::SYNC_VALIDATION_ERROR, $validator->errors());
            } else {
                return LoginService::getLoginResult(true, [], [], $custom_login_user);
            }
        } 
        catch (\Exception $ex) {
            \Log::error($ex);

            return LoginService::getLoginResult(SsoLoginErrorType::UNDEFINED_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        } 
        catch (\Throwable $ex) {
            \Log::error($ex);

            return LoginService::getLoginResult(SsoLoginErrorType::UNDEFINED_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        }
    }
    
    public static function appendActivateSwalButton($tools, LoginSetting $login_setting){
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
    }
}

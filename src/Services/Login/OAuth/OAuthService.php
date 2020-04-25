<?php
namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Illuminate\Http\Request;


/**
 * LoginService
 */
class OAuthService implements LoginServiceInterface
{
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

        if (boolval(config('exment.expart_mode', false))) {
            $form->url('oauth_redirect_url', exmtrans('login.redirect_url'))
            ->help(exmtrans('login.help.redirect_url'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::OAUTH]])]);
        } elseif (isset($login_setting)) {
            $form->display('oauth_redirect_url')->default($login_setting->exment_callback_url);
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
     * Execute login test callback
     *
     * @param Request $request
     * @return void
     */
    public static function loginTestCallback(Request $request, $login_setting)
    {
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, true);

        $custom_login_user = null;
        $message = null;
        try {
            $custom_login_user = OAuthUser::with($login_setting->provider_name, $socialiteProvider->user());

            $validator = LoginService::validateCustomLoginSync($custom_login_user->mapping_values);
            if ($validator->fails()) {
                return LoginService::getLoginTestResult(false, $validator->errors());
            } else {
                return LoginService::getLoginTestResult(true, [], $custom_login_user);
            }
        } catch (\Exception $ex) {
            \Log::error($ex);

            return LoginService::getLoginTestResult(false, [$ex]);
        }
    }
    
    public static function appendActivateSwalButton($tools, LoginSetting $login_setting){
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
    }
}

<?php
namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
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

            $validator = LoginService::validateCustomLoginSync($custom_login_user->getValidateArray());
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

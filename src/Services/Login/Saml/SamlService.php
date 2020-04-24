<?php
namespace Exceedone\Exment\Services\Login\Saml;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Illuminate\Http\Request;

/**
 * LoginService
 */
class SamlService implements LoginServiceInterface
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
        $saml2Auth = LoginSetting::getSamlAuth($login_setting, true);
        $saml2Auth->login();
    }

    
    /**
     * Execute login test callback
     *
     * @param Request $request
     * @return void
     */
    public static function loginTestCallback(Request $request, $login_setting)
    {
        $saml2Auth = LoginSetting::getSamlAuth($login_setting, true);

        $errors = $saml2Auth->acs();
        if (!empty($errors)) {
            return LoginService::getLoginTestResult(false, $errors);
        }

        $custom_login_user = SamlUser::with($login_setting->provider_name, $saml2Auth->getSaml2User());
        
        if(!is_nullorempty($custom_login_user->mapping_errors)){
            return LoginService::getLoginTestResult(false, $custom_login_user->mapping_errors);
        }

        $validator = LoginService::validateCustomLoginSync($custom_login_user->getValidateArray());
        if ($validator->fails()) {
            return LoginService::getLoginTestResult(false, $validator->errors());
        }
        
        return LoginService::getLoginTestResult(true, [], $custom_login_user);
    }
}

<?php
namespace Exceedone\Exment\Services\Login\OAuth;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\CustomLoginUser;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login\Ldap\LdapService;
use Exceedone\Exment\Services\Login\Ldap\LdapUser;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

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
    public static function loginTest(Request $request, $login_setting){
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
    public static function loginTestCallback(Request $request, $login_setting){
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_setting, true);

        $custom_login_user = null;
        $message = null;
        try {
            $custom_login_user = OAuthUser::with($login_setting->provider_name, $socialiteProvider->user());

            $validator = LoginService::validateCustomLoginSync($custom_login_user->getValidateArray());
            if($validator->fails()){
                return LoginService::getLoginTestResult(false, $validator->errors());
            }
            else{
                return LoginService::getLoginTestResult(true, [], $custom_login_user);
            }
        } catch (\Exception $ex) {
            \Log::error($ex);

            return LoginService::getLoginTestResult(false, [$ex]);
        }
    }

}

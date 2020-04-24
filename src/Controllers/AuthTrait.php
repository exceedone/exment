<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\LoginService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\PasswordHistory;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Auth\CustomLoginUserBase;
use Exceedone\Exment\Auth\SamlUser;
use Exceedone\Exment\Auth\OAuthUser;
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Auth\CustomLoginTrait;
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Providers\CustomUserProvider;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

trait AuthTrait
{
    use CustomLoginTrait;

    public function getLoginPageData($array = [])
    {
        $array['site_name'] = System::site_name();

        // get login settings
        $login_settings = LoginSetting::getOAuthSettings()->merge(LoginSetting::getSamlSettings());

        if (!is_nullorempty($login_settings)) {
            // create login provider items for login page
            $login_provider_items = [];
            foreach($login_settings as $login_setting){
                $login_provider_items[$login_setting->provider_name] =  $login_setting->getLoginButton();
            }

            $array['login_providers'] = $login_provider_items;
            $array['show_default_login_provider']= config('exment.show_default_login_provider', false) || boolval(System::show_default_login_provider() ?? false);
        } else {
            $array['login_providers'] = [];
            $array['show_default_login_provider']= true;
        }

        return $array;
    }


    protected function logoutSso(Request $request, $login_user, $options = []){
        if($login_user->login_type == LoginType::SAML){
            return $this->logoutSaml($request, $login_user->login_provider, $options);
        }

        return redirect(\URL::route('exment_login'));
    }

    /**
     * Initiate a logout request across all the SSO infrastructure.
     *
     */
    protected function logoutSaml(Request $request, $provider_name, $options = [])
    {
        $login_setting = LoginSetting::getSamlSetting($provider_name);
        
        // if not set ssout_url, return login
        if(is_nullorempty($login_setting->getOption('saml_idp_ssout_url'))){
            return redirect(\URL::route('exment_login'));
        }

        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        
        $returnTo = \URL::route('saml_sls');
        $sessionIndex = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.sessionIndex');
        $nameId = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.nameId');
        $saml2Auth->logout($returnTo, $nameId, $sessionIndex, $login_setting->name_id_format_string); //will actually end up in the sls endpoint
        //does not return
    }
    
    protected function executeLogin(Request $request, CustomLoginUserBase $custom_login_user, $socialiteProvider = null, $successCallback = null){
        $error_url = admin_url('auth/login');
        
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->throttle && $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        // check exment user
        $exment_user = $this->getExmentUser($custom_login_user);
        if ($exment_user === false) {
            // Check system setting jit
            $exment_user = $this->createExmentUser($custom_login_user, $error_url);
            if($exment_user instanceof Response){
                $this->incrementLoginAttempts($request);
                return $exment_user;
            }
        }

        $login_user = $this->getLoginUser($custom_login_user, $exment_user, $socialiteProvider);
        
        if ($this->guard()->attempt(
            [
                'username' => $custom_login_user->login_id,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
                'password' => $custom_login_user->dummy_password,
            ]
        )) {
            // set session for 2factor
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
            
            if($successCallback){
                $successCallback($custom_login_user);
            }

            return $this->sendLoginResponse($request);
        }

        $this->incrementLoginAttempts($request);

        return redirect($error_url)->withInput()->withErrors(['sso_error' => $this->getFailedLoginMessage()]);
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

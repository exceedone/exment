<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\CustomLoginUserBase;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

trait AuthTrait
{
    public function getLoginPageData($array = [])
    {
        $array['site_name'] = System::site_name();

        // get login settings
        $login_settings = LoginSetting::getOAuthSettings()->merge(LoginSetting::getSamlSettings());

        if (!is_nullorempty($login_settings)) {
            // create login provider items for login page
            $login_provider_items = [];
            foreach ($login_settings as $login_setting) {
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


    protected function logoutSso(Request $request, $login_user, $options = [])
    {
        if ($login_user->login_type == LoginType::SAML) {
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
        if (is_nullorempty($login_setting->getOption('saml_idp_ssout_url'))) {
            return redirect(\URL::route('exment_login'));
        }

        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        
        $returnTo = \URL::route('saml_sls');
        $sessionIndex = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.sessionIndex');
        $nameId = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.nameId');
        $saml2Auth->logout($returnTo, $nameId, $sessionIndex, $login_setting->name_id_format_string); //will actually end up in the sls endpoint
        //does not return
    }
    
    protected function executeLogin(Request $request, CustomLoginUserBase $custom_login_user, $socialiteProvider = null, $successCallback = null)
    {
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
            if ($exment_user instanceof Response) {
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
            
            if ($successCallback) {
                $successCallback($custom_login_user);
            }

            return $this->sendLoginResponse($request);
        }

        $this->incrementLoginAttempts($request);

        return redirect($error_url)->withInput()->withErrors(['sso_error' => $this->getFailedLoginMessage()]);
    }

    
    /**
     * get exment user from users table
     */
    protected function getExmentUser(CustomLoginUserBase $custom_login_user)
    {
        $exment_user = getModelName(SystemTableName::USER)
            ::where("value->{$custom_login_user->mapping_user_column}", $custom_login_user->login_id)
            ->first();
        if (!isset($exment_user)) {
            return false;
        }

        // update user info
        if (boolval($custom_login_user->login_setting->getOption('update_user_info'))) {
            $exment_user->setValue([
                'user_name' => $custom_login_user->user_name
            ]);
            $exment_user->save();
        }

        return $exment_user;
    }

    /**
     * create exment user from users table
     */
    protected function createExmentUser(CustomLoginUserBase $custom_login_user, $error_url)
    {
        if (!boolval($custom_login_user->login_setting->getOption('sso_jit'))) {
            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => exmtrans('login.noexists_user')]
            );
        }

        if (!is_nullorempty($sso_accept_mail_domains = System::sso_accept_mail_domain())) {
            // check domain
            $email_domain = explode("@", $custom_login_user->email)[1];
            $domain_result = false;
            foreach (explodeBreak($sso_accept_mail_domains) as $sso_accept_mail_domain) {
                if ($email_domain == $sso_accept_mail_domain) {
                    $domain_result = true;
                    break;
                }
            }
                
            if (!$domain_result) {
                return redirect($error_url)->withInput()->withErrors(
                    ['sso_error' => exmtrans('login.not_accept_domain')]
                );
            }
        }

        $exment_user = null;
        \DB::transaction(function () use ($custom_login_user, &$exment_user) {
            $exment_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
            $exment_user->setValue([
                'user_name' => $custom_login_user->user_name,
                'user_code' => $custom_login_user->user_code,
                'email' => $custom_login_user->email,
            ]);
            $exment_user->save();
    
            // Set roles
            if (!is_nullorempty($jit_rolegroups = System::jit_rolegroups())) {
                $jit_rolegroups = collect(System::jit_rolegroups())->map(function ($sso_rolegroup) use ($exment_user) {
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
     */
    protected function getLoginUser(CustomLoginUserBase $custom_login_user, $exment_user, $socialiteProvider = null)
    {
        $hasLoginUser = false;
        // get login_user
        $login_user = CustomUserProvider::RetrieveByCredential(
            [
                'username' => $custom_login_user->email,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]
        );
        if (isset($login_user)) {
            // check password
            if (CustomUserProvider::ValidateCredential($login_user, [
                'password' => $custom_login_user->dummy_password
            ])) {
                $hasLoginUser = true;
            }
        }

        // if don't has, create loginuser or match email
        if (!$hasLoginUser) {
            $login_user = LoginUser::firstOrNew([
                'base_user_id' => $exment_user->id,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]);
            $login_user->base_user_id = $exment_user->id;
            $login_user->login_provider = $custom_login_user->provider_name;
            $login_user->password = $custom_login_user->dummy_password;
        }

        // get avatar
        if (!$hasLoginUser || boolval($custom_login_user->login_setting->getOption('update_user_info'))) {
            $avatar  = $this->getAvatar($custom_login_user, $socialiteProvider = null);
            if (isset($avatar)) {
                $login_user->avatar = $avatar;
            }
        }

        $login_user->save();
        return $login_user;
    }

    protected function getAvatar(CustomLoginUserBase $custom_login_user, $socialiteProvider = null)
    {
        try {
            // if socialiteProvider implements ProviderAvatar, call getAvatar
            if (isset($socialiteProvider) && is_subclass_of($socialiteProvider, ProviderAvatar::class)) {
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
            if (isset($stream)) {
                $file = ExmentFile::put(path_join("avatar", $custom_login_user->id), $stream, true);
                return $file->path;
            }
        } finally {
        }
        return null;
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

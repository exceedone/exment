<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Providers\CustomUserProvider;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

trait AuthTrait
{

    public function getLoginPageData($array = [])
    {
        $array['site_name'] = System::site_name();

        // if sso_disabled is true
        if (boolval(config('exment.custom_login_disabled', false))) {
            $array['login_providers'] = [];
            $array['form_providers'] = [];
            $array['show_default_login_provider'] = true;
        }else{
            // get login settings
            $array['login_providers'] = LoginSetting::getSSOSettings()->mapWithKeys(function($login_setting){
                return [$login_setting->provider_name => $login_setting->getLoginButton()];
            })->toArray();
            $array['form_providers'] = LoginSetting::getLdapSettings()->mapWithKeys(function($login_setting){
                return [$login_setting->provider_name => $login_setting->getLoginButton()];
            })->toArray();

            $array['show_default_login_provider'] = LoginSetting::isUseDefaultLoginForm();
        }

        $array['show_default_form'] = $array['show_default_login_provider'] || count($array['form_providers']) > 0;

        return $array;
    }

    protected function logoutSso(Request $request, $login_user, $options = [])
    {
        if ($login_user->login_type == LoginType::SAML) {
            return $this->logoutSaml($request, $login_user->login_provider, $options);
        }

        return redirect(\URL::route('exment.login'));
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
            return redirect(\URL::route('exment.login'));
        }

        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        
        $returnTo = route('exment.saml_sls');
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
        
        $this->guard()->login($login_user);
        if ($successCallback) {
            $successCallback($custom_login_user);
        }

        return $this->sendLoginResponse($request);
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
            // update only init_flg is false
            $update_user_columns = $this->getUserColumns()->filter(function($column){
                return !boolval($column->getOption('init_flg'));
            });

            $values = $update_user_columns->mapWithKeys(function($column) use($custom_login_user){
                return [$column->column_name => array_get($custom_login_user->mapping_values, $column->column_name)];
            });

            $exment_user->setValue($values);
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
            $email_domain = explode("@", $custom_login_user->email())[1];
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

            $update_user_columns = $this->getUserColumns();
            $values = $update_user_columns->mapWithKeys(function($column) use($custom_login_user){
                return [$column->column_name => array_get($custom_login_user->mapping_values, $column->column_name)];
            });

            $exment_user->setValue($values);
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
                'target_column' => $custom_login_user->mapping_user_column,
                'username' => $custom_login_user->login_id,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]
        );
        
        // if don't has, create loginuser or match email
        if (!$hasLoginUser) {
            $login_user = LoginUser::firstOrNew([
                'base_user_id' => $exment_user->id,
                'login_provider' => $custom_login_user->provider_name,
                'login_type' => $custom_login_user->login_type,
            ]);
            $login_user->base_user_id = $exment_user->id;
            $login_user->login_provider = $custom_login_user->provider_name;
            $login_user->password = make_password(32);
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
            if (isset($stream) && isset($user->id)) {
                $file = ExmentFile::put(path_join("avatar", $user->id), $stream, true);
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

    protected function getUserColumns(){
        return CustomTable::getEloquent(SystemTableName::USER)->custom_columns_cache;
    }
}

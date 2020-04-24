<?php

namespace Exceedone\Exment\Auth;

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
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Providers\CustomUserProvider;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

trait CustomLoginTrait
{
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
        if(boolval($custom_login_user->login_setting->getOption('update_user_info'))){
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
        if(!boolval($custom_login_user->login_setting->getOption('sso_jit'))){
            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => exmtrans('login.noexists_user')]
            );
        }

        if(!is_nullorempty($sso_accept_mail_domains = System::sso_accept_mail_domain())){
            // check domain
            $email_domain = explode("@", $custom_login_user->email)[1];
            $domain_result = false;
            foreach(explodeBreak($sso_accept_mail_domains) as $sso_accept_mail_domain){
                if($email_domain == $sso_accept_mail_domain){
                    $domain_result = true;
                    break;
                }
            }
                
            if(!$domain_result){
                return redirect($error_url)->withInput()->withErrors(
                    ['sso_error' => exmtrans('login.not_accept_domain')]
                );
            }
        }

        $exment_user = null;
        \DB::transaction(function() use($custom_login_user, &$exment_user){
            $exment_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
            $exment_user->setValue([
                'user_name' => $custom_login_user->user_name,
                'user_code' => $custom_login_user->user_code,
                'email' => $custom_login_user->email,
            ]);
            $exment_user->save();
    
            // Set roles
            if(!is_nullorempty($jit_rolegroups = System::jit_rolegroups())){
                $jit_rolegroups = collect(System::jit_rolegroups())->map(function($sso_rolegroup) use($exment_user){
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
        if(!$hasLoginUser || boolval($custom_login_user->login_setting->getOption('update_user_info'))){
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

}

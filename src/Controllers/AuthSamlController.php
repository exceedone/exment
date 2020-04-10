<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\LoginService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
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
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Providers\CustomUserProvider;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

/**
 * For login saml controller
 */
class AuthSamlController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait, ThrottlesLogins;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * Login page using provider (SSO).
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLoginProvider(Request $request, $login_provider)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        // provider check
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_provider);
        if (!isset($socialiteProvider)) {
            abort(404);
        }
        return $socialiteProvider->redirect();
    }

    /**
     * callback login provider and login exment
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function callbackLoginProvider(Request $request, $login_provider)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        $error_url = admin_url('auth/login');
        
        $socialiteProvider = LoginSetting::getSocialiteProvider($login_provider);

        // get provider user
        $provider_user = null;
        try {
            $provider_user = $socialiteProvider->user();
        } catch (\Exception $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
                [$this->username() => exmtrans('login.sso_provider_error')]
            );
        }

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->throttle && $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        // check exment user
        $exment_user = $this->getExmentUser($provider_user);
        if ($exment_user === false) {
            // Check system setting jit
            $exment_user = $this->createExmentUser($provider_user, $error_url);
            if($exment_user instanceof Response){
                $this->incrementLoginAttempts($request);
                return $exment_user;
            }
        }

        $login_user = $this->getLoginUser($socialiteProvider, $login_provider, $exment_user, $provider_user);
        
        if ($this->guard()->attempt(
            [
                'username' => $provider_user->email,
                'login_provider' => $login_provider,
                'password' => $provider_user->id,
            ]
        )) {
            // set session for 2factor
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
            
            // set session access key
            LoginService::setToken($login_provider, $provider_user);

            return $this->sendLoginResponse($request);
        }

        $this->incrementLoginAttempts($request);

        return redirect($error_url)->withInput()->withErrors([$this->username() => $this->getFailedLoginMessage()]);
    }

    /**
     * get exment user from users table
     */
    protected function getExmentUser($provider_user)
    {
        $exment_user = getModelName(SystemTableName::USER)
            ::where('value->email', $provider_user->email)
            ->first();
        if (!isset($exment_user)) {
            return false;
        }

        // update user info
        $exment_user->setValue([
            'user_name' => $provider_user->name ?: $provider_user->email
        ]);
        $exment_user->save();

        return $exment_user;
    }

    /**
     * create exment user from users table
     */
    protected function createExmentUser($provider_user, $error_url)
    {
        if(!System::sso_jit()){
            return redirect($error_url)->withInput()->withErrors(
                [$this->username() => exmtrans('login.noexists_user')]
            );
        }

        if(!is_nullorempty($sso_accept_mail_domains = System::sso_accept_mail_domain())){
            // check domain
            $email_domain = explode("@", $provider_user->email)[1];
            $domain_result = false;
            foreach(explodeBreak($sso_accept_mail_domains) as $sso_accept_mail_domain){
                if($email_domain == $sso_accept_mail_domain){
                    $domain_result = true;
                    break;
                }
            }
                
            if(!$domain_result){
                return redirect($error_url)->withInput()->withErrors(
                    [$this->username() => exmtrans('login.not_accept_domain')]
                );
            }
        }


        $exment_user = null;
        \DB::transaction(function() use($provider_user, &$exment_user){
            $exment_user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
            $exment_user->setValue([
                'user_name' => $provider_user->name ?: $provider_user->email,
                'user_code' => $provider_user->id,
                'email' => $provider_user->email,
            ]);
            $exment_user->save();
    
            // Set roles
            if(!is_nullorempty($sso_rolegroups = System::sso_rolegroups())){
                $sso_rolegroups = collect(System::sso_rolegroups())->map(function($sso_rolegroup) use($exment_user){
                    return [
                        'role_group_id' => $sso_rolegroup,
                        'role_group_user_org_type' => SystemTableName::USER,
                        'role_group_target_id' => $exment_user->id,
                    ];
                })->toArray();
                    
                \DB::table(SystemTableName::ROLE_GROUP_USER_ORGANIZATION)->insert($sso_rolegroups);
            }
        });

        return $exment_user;
    }

    /**
     * get login_user from login_users table
     */
    protected function getLoginUser($socialiteProvider, $login_provider, $exment_user, $provider_user)
    {
        $hasLoginUser = false;
        // get login_user
        $login_user = CustomUserProvider::RetrieveByCredential(
            [
                'username' => $provider_user->email,
                'login_provider' => $login_provider
            ]
        );
        if (isset($login_user)) {
            // check password
            if (CustomUserProvider::ValidateCredential($login_user, [
                'password' => $provider_user->id
            ])) {
                $hasLoginUser = true;
            }
        }

        // get avatar
        $avatar  = $this->getAvatar($socialiteProvider, $provider_user);

        // if don't has, create loginuser or match email
        if (!$hasLoginUser) {
            $login_user = LoginUser::firstOrNew([
                'base_user_id' => $exment_user->id,
                'login_provider' => $login_provider,
            ]);
            $login_user->base_user_id = $exment_user->id;
            $login_user->login_provider = $login_provider;
            $login_user->password = $provider_user->id;
        }

        if (isset($avatar)) {
            $login_user->avatar = $avatar;
        }
        $login_user->save();
        return $login_user;
    }

    protected function getAvatar($socialiteProvider, $provider_user)
    {
        try {
            // if socialiteProvider implements ProviderAvatar, call getAvatar
            if (is_subclass_of($socialiteProvider, ProviderAvatar::class)) {
                $stream = $socialiteProvider->getAvatar($provider_user->token);
            }
            // if user obj has avatar, download avatar.
            elseif (isset($provider_user->avatar)) {
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', $provider_user->avatar, [
                    'http_errors' => false,
                ]);
                $stream = $response->getBody()->getContents();
            }
            // file upload.
            if (isset($stream)) {
                $file = ExmentFile::put(path_join("avatar", $provider_user->id), $stream, true);
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

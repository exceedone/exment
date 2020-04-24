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
use Exceedone\Exment\Auth\OAuthUser;
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
 * For login oauth controller
 */
class AuthOAuthController extends \Encore\Admin\Controllers\AuthController
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
    public function callback(Request $request, $login_provider)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        $socialiteProvider = LoginSetting::getSocialiteProvider($login_provider);
        if (!isset($socialiteProvider)) {
            abort(404);
        }

        // get sso user
        $error_url = admin_url('auth/login');
        $custom_login_user = null;
        try {
            $custom_login_user = OAuthUser::with($login_provider, $socialiteProvider->user());
        } catch (\Exception $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => exmtrans('login.sso_provider_error')]
            );
        }

        return $this->executeLogin($request, $custom_login_user, $socialiteProvider, function($custom_login_user){
            // set session access key
            LoginService::setToken($custom_login_user);
        });
    }
}

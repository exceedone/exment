<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login\OAuth\OAuthUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Illuminate\Http\Request;

/**
 * For login oauth controller
 */
class AuthOAuthController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * Login page using provider (SSO).
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
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
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
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

        return $this->executeLogin($request, $custom_login_user, $socialiteProvider, function ($custom_login_user) {
            session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

            // set session access key
            LoginService::setToken($custom_login_user);
        });
    }
}

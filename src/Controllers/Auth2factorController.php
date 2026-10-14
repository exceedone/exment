<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorRecoveryCodeService;
use Illuminate\Http\Request;

/**
 * For login controller 2 factor
 */
class Auth2factorController extends \ExmentAdminCore\Admin\Controllers\AuthController
{
    use AuthTrait;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * User logout.
     */
    // @phpstan-ignore-next-line
    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        return redirect(config('admin.route.prefix'));
    }

    /**
     * Login by a recovery code, instead of the one-time password: input page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function recovery()
    {
        $login_user = \Exment::user();

        return view('exment::auth.2factor.2factor-recovery', $this->getLoginPageData([
            'has_codes' => Auth2factorService::canUseRecoveryCodes($login_user)
                && Auth2factorRecoveryCodeService::getRemainingCount($login_user) > 0,
        ]));
    }

    /**
     * Login by a recovery code: verify. The matched code is consumed.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     */
    public function recoveryVerify(Request $request)
    {
        if ($this->throttle && $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            $this->sendLockoutResponse($request, 'recovery_code');
            return;
        }

        $login_user = \Exment::user();
        $code = $request->get('recovery_code');

        // a user who has not set up 2factor must not pass it by a recovery code
        if (!is_string($code)
            || !Auth2factorService::canUseRecoveryCodes($login_user)
            || !Auth2factorRecoveryCodeService::verify($login_user, $code)) {
            $this->incrementLoginAttempts($request);

            return back()->withErrors([
                'recovery_code' => exmtrans('2factor.message.recovery_verify_failed'),
            ]);
        }

        $this->clearLoginAttempts($request);

        // never issue new recovery codes here: the user who has a stolen code must not get the other codes.
        // tell the remaining count instead of "logged in".
        $remaining = Auth2factorRecoveryCodeService::getRemainingCount($login_user);
        return Auth2factorService::sendVerifiedResponse(
            $login_user,
            false,
            exmtrans('2factor.message.recovery_login_succeeded', $remaining),
            'warning'
        );
    }

    /**
     * Show the recovery codes just issued. They are shown only this once.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse
     */
    public function recoveryCodes()
    {
        $codes = session()->pull(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR_RECOVERY_CODES);
        if (!is_array($codes) || count($codes) == 0) {
            return redirect(admin_url(''));
        }

        return view('exment::auth.2factor.2factor-recovery-codes', $this->getLoginPageData([
            'codes' => $codes,
            'continue_url' => admin_url(''),
        ]));
    }

    /**
     * Throttle key of the recovery code form: per login user and IP address.
     *
     * @param Request $request
     * @return string
     */
    protected function throttleKey(Request $request)
    {
        return 'auth2factor_recovery|' . \Exment::getUserId() . '|' . $request->ip();
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    // @phpstan-ignore-next-line
    public function __call($method, $parameters)
    {
        $provider = Auth2factorService::getProvider();
        if (method_exists($provider, $method)) {
            return $provider->$method();
        }

        parent::__call($method, $parameters);
    }
}

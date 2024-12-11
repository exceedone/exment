<?php

namespace Exceedone\Exment\Services\Auth2factor\Providers;

use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Controllers\AuthTrait;
use Carbon\Carbon;

/**
 * For login controller 2 factor
 */
class Email
{
    use AuthTrait;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * Handle index
     *
     * @return mixed
     */
    public function index()
    {
        return view('exment::auth.2factor.2factor-email', $this->getLoginPageData([
            'email_send_verify' => exmtrans('2factor.message.email_send_verify', config('exment.login_2factor_valid_period', 10))
        ]));
    }

    /**
     * Handle verify posting
     *
     * @return mixed
     */
    public function verify()
    {
        $request = request();

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->throttle && $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            $this->sendLockoutResponse($request, 'verify_code');
            return;
        }

        $verify_code = $request->get('verify_code');
        $loginuser = \Admin::user();

        if (!Auth2factorService::verifyCode('email', $verify_code, true)) {
            $this->incrementLoginAttempts($request);

            // error
            return back()->withInput()->withErrors([
                'verify_code' => exmtrans('2factor.message.verify_failed')
            ]);
        }

        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        admin_toastr(trans('admin.login_successful'));

        return redirect(admin_url(''));
    }

    public function insertVerify()
    {
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinutes(config('exment.login_2factor_valid_period', 10));

        // send verify
        if (!Auth2factorService::addAndSendVerify('email', $verify_code, $valid_period_datetime, MailKeyName::VERIFY_2FACTOR, [
            'verify_code' => $verify_code,
            'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
        ])) {
            // show warning message
            admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
        }
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }
}

<?php

namespace Exceedone\Exment\Services\Auth2factor\Providers;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Controllers\AuthTrait;
use PragmaRX\Google2FA\Google2FA;
use Carbon\Carbon;

/**
 * For login 2 factor
 */
class Google
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
        $loginUser = \Admin::user();

        // if not available, send email
        if (!boolval($loginUser->auth2fa_available)) {
            return view('exment::auth.2factor.2factor-google-email', $this->getLoginPageData([
                'message_available' => exmtrans('2factor.message.google.message_available')
            ]));
        }

        return view('exment::auth.2factor.2factor-google-verify', $this->getLoginPageData());
    }

    /**
     * Handle index
     *
     * @return mixed
     */
    public function sendmail()
    {
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = str_random(32);
        $valid_period_datetime = Carbon::now()->addMinutes(60);
        $register_url = admin_urls('auth-2factor', 'google', 'register?code=' . $verify_code);

        // send verify
        if (!Auth2factorService::addAndSendVerify('google', $verify_code, $valid_period_datetime, 'verify_2factor_google', [
            '2factor_google_register_url' => $register_url,
            'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
        ])) {
            // show warning message
            admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
        }

        return view('exment::auth.2factor.2factor-google-email-sended', $this->getLoginPageData());
    }

    /**
     * Handle verify posting
     *
     * @return mixed
     */
    public function register()
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

        $verify_code = $request->get('code');
        $loginuser = \Admin::user();

        if (!Auth2factorService::verifyCode('google', $verify_code)) {
            $this->incrementLoginAttempts($request);

            // error
            return redirect()->back()
                ->withErrors(['code' => '']);
        }

        $g2fa = $this->getG2fa();

        if (!isset($loginuser->auth2fa_key)) {
            // Create SecretKey
            $key = $g2fa->generateSecretKey();

            $loginuser->auth2fa_key = encrypt($key);
            $loginuser->save();
        } else {
            $key = decrypt($loginuser->auth2fa_key);
        }

        $qrUrl = $g2fa->getQRCodeUrl(
            System::site_name(),
            $loginuser->email,
            $key
        );
        $qrSrc = base64_encode(\QrCode::format('png')->size(200)->generate($qrUrl));

        // android and iphone url
        $urlAndroid = 'https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2';
        $urlIphone = 'https://apps.apple.com/jp/app/google-authenticator/id388497605';

        $qrSrcAndroid = base64_encode(\QrCode::format('png')->size(100)->generate($urlAndroid));
        $qrSrcIphone = base64_encode(\QrCode::format('png')->size(100)->generate($urlIphone));

        return view('exment::auth.2factor.2factor-google-register', $this->getLoginPageData([
            'qrSrc' => $qrSrc,
            'qrSrcAndroid' => $qrSrcAndroid,
            'qrSrcIphone' => $qrSrcIphone,
            'urlAndroid' => $urlAndroid,
            'urlIphone' => $urlIphone,
            'code' => $verify_code,
            'key' => $key,
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
        $g2fa = $this->getG2fa();
        $loginuser = \Admin::user();
        $key = decrypt($loginuser->auth2fa_key);

        // validation google 2fa
        if (!$g2fa->verifyKey($key, $verify_code, 5)) {
            $this->incrementLoginAttempts($request);

            return back()->withInput()->withErrors([
                'verify_code' => exmtrans('2factor.message.verify_failed')
            ]);
        }

        if (!boolval($loginuser->auth2fa_available)) {
            $loginuser->auth2fa_available = true;
            $loginuser->save();
        }

        // get from database
        if ($request->has('code')) {
            Auth2factorService::verifyCode('google', $request->get('code'), true);
        }

        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        admin_toastr(trans('admin.login_successful'));

        return redirect(admin_url(''));
    }

    public function insertVerify()
    {
    }

    protected function getG2fa()
    {
        $g2fa = new Google2FA();
        return $g2fa;
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

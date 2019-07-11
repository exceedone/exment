<?php

namespace Exceedone\Exment\Services\Auth2factor\Providers;

use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\MailKeyName;
use Illuminate\Http\Request;
use Exceedone\Exment\Controllers\AuthTrait;
use Carbon\Carbon;

/**
 * For login controller 2 factor
 */
class Email
{
    use AuthTrait;

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
     * @param Request $request
     *
     * @return mixed
     */
    public function verify()
    {
        $request = request();
        $verify_code = $request->get('verify_code');
        $loginuser = \Admin::user();
        
        if(!Auth2factorService::verifyCode('email', $verify_code, true)){
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

    public function insertVerify(){
        
        $loginuser = \Admin::user();

        // set 2factor params
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinute(config('exment.login_2factor_valid_period', 10));

        // send verify
        if(!Auth2factorService::addAndSendVerify('email', $verify_code, $valid_period_datetime, MailKeyName::VERIFY_2FACTOR, [
            'verify_code' => $verify_code,
            'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
        ])){
            // show warning message
            admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
        }
    }
}

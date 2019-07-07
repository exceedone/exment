<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Providers\CustomUserProvider;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Request as Req;
use Exceedone\Exment\Controllers\AuthTrait;

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
        return view('exment::auth.2factor-email', $this->getLoginPageData([
            'email_send_vorify' => exmtrans('2factor.message.email_send_vorify', config('exment.2factor_valid_period', 10))
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

        // remove old datetime value
        \DB::table(SystemTableName::LOGIN_2FACTOR_VERIFY)
            ->where('valid_period_datetime', '<', \Carbon\Carbon::now())
            ->delete();

        // get from database
        $verify = \DB::table(SystemTableName::LOGIN_2FACTOR_VERIFY)
            ->where('verify_code', $verify_code)
            ->where('email', $loginuser->email)
            ->where('login_user_id', $loginuser->id)
            ->first();
        if(!isset($verify)){
            // error
            return back()->withInput()->withErrors([
                'verify_code' => exmtrans('2factor.message.verify_failed')
            ]);
        }

        \DB::table(SystemTableName::LOGIN_2FACTOR_VERIFY)
            ->where('id', $verify->id)
            ->delete();

        // set session for 2factor
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        admin_toastr(trans('admin.login_successful'));

        return redirect(admin_url(''));
    }
}

<?php

namespace Exceedone\Exment\Controllers;

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

/**
 * For login controller 2 factor
 */
class Auth2factorController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;

    /**
     * Handle index
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function index(Request $request)
    {
        return $this->get2factorProvider()->index();
    }
    
    /** 
     * Handle verify posting
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function verify(Request $request)
    {
        return $this->get2factorProvider()->verify();
    }

    /**
     * User logout.
     */
    public function logout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        return redirect(config('admin.route.prefix'));
    }

    protected function get2factorProvider(){
        return new \Exceedone\Exment\Services\Auth2factor\Email;
    }
}

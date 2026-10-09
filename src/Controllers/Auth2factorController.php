<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Illuminate\Http\Request;

/**
 * For login controller 2 factor
 */
class Auth2factorController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;

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

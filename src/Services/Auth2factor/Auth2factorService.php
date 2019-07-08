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
 * For login 2 factor
 */
class Auth2factorService
{
    protected static $providers = [
    ];

    /**
     * Register providers.
     *
     * @param string $abstract
     * @param string $class
     *
     * @return void
     */
    public static function providers($abstract, $class)
    {
        static::$providers[$abstract] = $class;
    }

    public static function getProvider(){
        $provider = config('exment.login_2factor_provider', 'email');

        if(!array_has(static::$providers, $provider)){
            throw new \Exception("Login 2factor provider [$provider] does not exist.");
        }

        return new static::$providers[$provider];        
    }
}

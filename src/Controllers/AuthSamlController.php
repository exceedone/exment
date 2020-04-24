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
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Exceedone\Exment\Auth\SamlUser;
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
 * For login saml controller
 */
class AuthSamlController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait, ThrottlesLogins;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * metadata
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function metadata(Request $request, $provider_name)
    {
        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        if (!isset($saml2Auth)) {
            abort(404);
        }

        $metadata = $saml2Auth->getMetadata();

        return response($metadata, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Login page using provider (SSO).
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function login(Request $request, $provider_name)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        if (!isset($saml2Auth)) {
            abort(404);
        }
        
        $saml2Auth->login();
    }

    /**
     * Process an incoming saml2 assertion request.
     * Fires 'Saml2LoginEvent' event if a valid user is found.
     *
     * @return \Illuminate\Http\Response
     */
    public function acs(Request $request, $provider_name)
    {
        $saml2Auth = LoginSetting::getSamlAuth($provider_name);
        
        $errors = $saml2Auth->acs();
        $error_url = admin_url('auth/login');

        if (!empty($errors)) {
            logger()->error('Saml2 error_detail', ['error' => $saml2Auth->getLastErrorReason()]);
            session()->flash('saml2_error_detail', [$saml2Auth->getLastErrorReason()]);

            logger()->error('Saml2 error', $errors);
            session()->flash('saml2_error', $errors);
            return redirect($error_url);
        }

        $custom_login_user = SamlUser::with($provider_name, $saml2Auth->getSaml2User());
        return $this->executeLogin($request, $custom_login_user, null, function() use($saml2Auth){
            session([Define::SYSTEM_KEY_SESSION_SAML_SESSION => [
                'sessionIndex' => $saml2Auth->getSaml2User()->getSessionIndex(),
                'nameId' => $saml2Auth->getSaml2User()->getNameId(),
            ]]);
        });
    }

    /**
     * Process an incoming saml2 logout request.
     * Fires 'Saml2LogoutEvent' event if its valid.
     * This means the user logged out of the SSO infrastructure, you 'should' log them out locally too.
     *
     * @param Saml2Auth $saml2Auth
     * @param $idpName
     * @return \Illuminate\Http\Response
     */
    public function sls(Request $request)
    {
        $this->guard()->logout();
        $request->session()->invalidate();

        return redirect(\URL::route('exment_login')); //may be set a configurable default
    }

}

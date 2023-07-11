<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Illuminate\Http\Request;

/**
 * For login saml controller
 */
class AuthSamlController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;

    public function __construct()
    {
        $this->maxAttempts = config("exment.max_attempts", 5);
        $this->decayMinutes = config("exment.decay_minutes", 60);

        $this->throttle = config("exment.throttle", true);
    }

    /**
     * metadata
     *
     * @param Request $request
     * @param $provider_name
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Exception
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
     * @param Request $request
     * @param $provider_name
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     */
    public function login(Request $request, $provider_name)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }
        $error_url = admin_url('auth/login');
        try {
            $saml2Auth = LoginSetting::getSamlAuth($provider_name);
            if (!isset($saml2Auth)) {
                abort(404);
            }

            $saml2Auth->login();
        }
        // Sso exception
        catch (SsoLoginErrorException $ex) {
            if ($ex->hasAdminError()) {
                \Log::error($ex);
            }

            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => $ex->getSsoErrorMessage()]
            );
        } catch (\Exception $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => exmtrans('login.sso_provider_error')]
            );
        }
    }

    /**
     * Process an incoming saml2 assertion request.
     * Fires 'Saml2LoginEvent' event if a valid user is found.
     *
     * @param Request $request
     * @param $provider_name
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function acs(Request $request, $provider_name)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        $error_url = admin_url('auth/login');
        try {
            $saml2Auth = LoginSetting::getSamlAuth($provider_name);

            $credentials = [
                'login_type' => LoginType::SAML,
                'login_setting' => LoginSetting::getSamlSetting($provider_name),
                'provider_name' => $provider_name,
                'islogin_from_provider' => true,
            ];

            if ($this->guard()->attempt($credentials)) {
                session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

                session([Define::SYSTEM_KEY_SESSION_SAML_SESSION => [
                    'sessionIndex' => $saml2Auth->getSaml2User()->getSessionIndex(),
                    'nameId' => $saml2Auth->getSaml2User()->getNameId(),
                ]]);

                return $this->sendLoginResponse($request);
            }

            // If the login attempt was unsuccessful we will increment the number of attempts
            // to login and redirect the user back to the login form. Of course, when this
            // user surpasses their maximum number of attempts they will get locked out.
            $this->incrementLoginAttempts($request);

            return back()->withInput()->withErrors([
                'sso_error' => $this->getFailedLoginMessage(),
            ]);
        }
        // Sso exception
        catch (SsoLoginErrorException $ex) {
            if ($ex->hasAdminError()) {
                \Log::error($ex);
            }

            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => $ex->getSsoErrorMessage()]
            );
        } catch (\Exception $ex) {
            \Log::error($ex);
            return redirect($error_url)->withInput()->withErrors(
                ['sso_error' => exmtrans('login.sso_provider_error')]
            );
        }
    }

    /**
     * Process an incoming saml2 logout request.
     * Fires 'Saml2LogoutEvent' event if its valid.
     * This means the user logged out of the SSO infrastructure, you 'should' log them out locally too.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function sls(Request $request)
    {
        $this->guard()->logout();
        $request->session()->invalidate();

        return redirect(\URL::route('exment.login')); //may be set a configurable default
    }
}

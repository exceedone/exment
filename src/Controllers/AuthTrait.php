<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Auth\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth trait, use from Auth Controller.
 *
 * @method string redirectPath()
 */
trait AuthTrait
{
    use ThrottlesLogins;

    protected $maxAttempts;
    protected $decayMinutes;
    protected $throttle;

    public function getLoginPageData($array = [])
    {
        $array['site_name'] = System::site_name();
        $array['background_color'] = System::login_background_color();

        $val = System::site_logo();
        if (!boolval(config('exment.disable_login_header_logo', false)) && !is_nullorempty($val)) {
            $array['header_image'] = admin_url('auth/file/header');
        }
        $val = System::login_page_image();
        if (!is_nullorempty($val)) {
            $array['background_image'] = admin_url('auth/file/background');
        }
        $val = System::login_page_image_type();
        if (!is_nullorempty($val)) {
            $array['background_image_type'] = $val;
        }

        // if sso_disabled is true
        if (boolval(config('exment.custom_login_disabled', false))) {
            $array['login_providers'] = [];
            $array['form_providers'] = [];
            $array['show_default_login_provider'] = true;
        } else {
            // get login settings
            $array['login_providers'] = LoginSetting::getSSOSettings()->mapWithKeys(function ($login_setting) {
                return [$login_setting->provider_name => $login_setting->getLoginButton()];
            })->toArray();
            $array['form_providers'] = LoginSetting::getLdapSettings()->mapWithKeys(function ($login_setting) {
                return [$login_setting->provider_name => $login_setting->getLoginButton()];
            })->toArray();

            $array['show_default_login_provider'] = LoginSetting::isUseDefaultLoginForm();
        }

        $array['show_default_form'] = $array['show_default_login_provider'] || count($array['form_providers']) > 0;

        return $array;
    }

    protected function logoutSso(Request $request, $login_user, $options = [])
    {
        if ($login_user->login_type == LoginType::SAML) {
            return $this->logoutSaml($request, $login_user->login_provider, $options);
        }

        return redirect(\URL::route('exment.login'));
    }

    /**
     * Initiate a logout request across all the SSO infrastructure.
     *
     * @param Request $request
     * @param $provider_name
     * @param $options
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     * @throws \OneLogin\Saml2\Error
     */
    protected function logoutSaml(Request $request, $provider_name, $options = [])
    {
        $login_setting = LoginSetting::getSamlSetting($provider_name);

        // if not set ssout_url, return login
        if (!isset($login_setting) || is_nullorempty($login_setting->getOption('saml_idp_ssout_url'))) {
            return redirect(\URL::route('exment.login'));
        }

        $saml2Auth = LoginSetting::getSamlAuth($provider_name);

        $returnTo = route('exment.saml_sls');
        $sessionIndex = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.sessionIndex');
        $nameId = array_get($options, Define::SYSTEM_KEY_SESSION_SAML_SESSION . '.nameId');
        $saml2Auth->logout($returnTo, $nameId, $sessionIndex, $login_setting->name_id_format_string); //will actually end up in the sls endpoint
        //does not return
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    protected function sendLoginResponse(Request $request)
    {
        admin_toastr(trans('admin.login_successful'));

        $request->session()->regenerate();

        return redirect()->intended($this->redirectPath());
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        if (Lang::has('exment::exment.error.login_failed')) {
            return exmtrans('error.login_failed');
        }
        /* TODO:used in a class that does not implement `getFailedLoginMessage` in the parent. */
        /* @phpstan-ignore-next-line */
        return parent::getFailedLoginMessage();
    }
}

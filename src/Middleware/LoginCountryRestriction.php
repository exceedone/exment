<?php

namespace Exceedone\Exment\Middleware;

use Illuminate\Http\Request;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginCountryRestrictionService;

/**
 * Login restriction by country, before the authentication of the login form and SSO.
 * Parameter: the login type of the route. "pure" is the login form (default or LDAP), "oauth", "saml" (provider name is the route parameter).
 */
class LoginCountryRestriction
{
    /**
     * @param Request $request
     * @param \Closure(Request): mixed $next
     * @param string $login_type
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, string $login_type = LoginType::PURE)
    {
        $service = app(LoginCountryRestrictionService::class);
        if (!$service->isEnabled()) {
            return $next($request);
        }

        $login_provider = null;
        if ($login_type == LoginType::PURE) {
            // LDAP login is posted to the login form with the provider. (same as AuthController::postLogin)
            foreach (LoginSetting::getLdapSettings() as $login_setting) {
                if ($request->has("login_setting_{$login_setting->provider_name}")) {
                    $login_type = LoginType::LDAP;
                    $login_provider = $login_setting->provider_name;
                    break;
                }
            }
        } else {
            $provider = $request->route('provider');
            $login_provider = is_string($provider) ? $provider : null;
        }

        return $service->handleLoginRequest($request, $login_type, $login_provider) ?? $next($request);
    }
}

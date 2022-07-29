<?php
/**
 * OAuth 2.0 Password grant.
 *
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace Exceedone\Exment\Auth;

use League\OAuth2\Server\Grant\PasswordGrant as PasswordGrantBase;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use RuntimeException;

/**
 * Password grant class.
 */
class PasswordGrant extends PasswordGrantBase
{
    /**
     * @param ServerRequestInterface $request
     * @param ClientEntityInterface  $client
     *
     * @throws OAuthServerException
     *
     * @return UserEntityInterface
     */
    protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client)
    {
        $login_type = $this->getRequestParameter('login_type', $request);
        if (is_null($login_type)) {
            return parent::validateUser($request, $client);
        }

        $username = $this->getRequestParameter('username', $request);
        if (is_null($username)) {
            throw OAuthServerException::invalidRequest('username');
        }

        $password = $this->getRequestParameter('password', $request);
        if (is_null($password)) {
            throw OAuthServerException::invalidRequest('password');
        }

        $provider_name = $this->getRequestParameter('provider_name', $request);
        if (is_null($provider_name)) {
            throw OAuthServerException::invalidRequest('provider_name');
        }

        if (!in_array($login_type, [LoginType::LDAP])) {
            throw new OAuthServerException("login_type {$login_type} is not supported", 3, 'invalid_request', 400);
        }

        $login_setting = null;
        if ($login_type == LoginType::LDAP) {
            $login_setting = LoginSetting::getLdapSetting($provider_name);
            if (!isset($login_setting)) {
                throw new OAuthServerException("provider {$provider_name} is not found", 3, 'invalid_request', 400);
            }
        }

        try {
            $user = $this->getUserEntityByUserCredentials(
                $username,
                $password,
                $login_type,
                $login_setting,
                $this->getIdentifier(),
                $client
            );
            if ($user instanceof UserEntityInterface === false) {
                $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

                throw OAuthServerException::invalidCredentials();
            }

            return $user;
        } catch (SsoLoginErrorException $ex) {
            throw new OAuthServerException($ex->getSsoErrorMessage(), 3, 'invalid_request', 400);
        }
    }


    /**
     * {@inheritdoc}
     */
    public function getUserEntityByUserCredentials($username, $password, $login_type, $login_setting, $grantType, ClientEntityInterface $clientEntity)
    {
        $provider = config('auth.guards.api.provider');

        if (is_null($model = config('auth.providers.'.$provider.'.model'))) {
            throw new RuntimeException('Unable to determine authentication model from configuration.');
        }

        $credentials = [
            'login_setting' => $login_setting,
            'login_type' => $login_type,
            'provider_name' => $login_setting->provider_name,
            'islogin_from_provider' => true,
        ];

        if (method_exists($model, 'findForPassport')) {
            $user = (new $model())->findForPassport($username, $credentials);
        } else {
            $user = (new $model())->where('email', $username)->first();
        }

        if (! $user) {
            return;
        } elseif (method_exists($user, 'validateForPassportPasswordGrant')) {
            if (! $user->validateForPassportPasswordGrant($password, $credentials)) {
                return;
            }
        }
        // elseif (! $this->hasher->check($password, $user->getAuthPassword())) {
        //     return;
        // }

        return new \Laravel\Passport\Bridge\User($user->getAuthIdentifier());
    }
}

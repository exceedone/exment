<?php

namespace Exceedone\Exment\Auth;

use Laravel\Passport\Bridge\User;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Exceedone\Exment\Model\ApiKey;
use Exceedone\Exment\Enums\SystemTableName;

class ApiKeyGrant extends AbstractGrant
{
    /**
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     */
    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokenRepository
    ) {
        $this->setRefreshTokenRepository($refreshTokenRepository);

        $this->refreshTokenTTL = new \DateInterval('P1M');
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        // Validate request
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request));
        $user = $this->validateUser($request, $client);

        // Finalize the requested scopes
        $scopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

        // Issue and persist new tokens
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $scopes);
        $refreshToken = $this->issueRefreshToken($accessToken);

        // Inject tokens into response
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        return $responseType;
    }

    protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client)
    {
        $api_key = $this->getRequestParameter('api_key', $request);
        if (is_null($api_key)) {
            throw OAuthServerException::invalidRequest('api_key');
        }

        $login_user = $this->getUserEntityByUserCredentials($api_key);

        if ($login_user instanceof UserEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidCredentials();
        }

        return $login_user;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'api_key';
    }

    /**
     * {@inheritdoc}
     */
    public function getUserEntityByUserCredentials($api_key)
    {
        /** @var ApiKey $api_key */
        $api_key = ApiKey::where('key', $api_key)->first();
        // @phpstan-ignore-next-line
        if (is_null($api_key) || is_null($api_key->client)) {
            throw OAuthServerException::invalidCredentials();
        }

        // this "user_id" is user table's id. not login user tbale's id.
        $user_id = $api_key->client->user_id;

        $user = getModelName(SystemTableName::USER)::find($user_id);
        $login_user = $user->login_user ?? null;
        if (is_null($login_user)) {
            return null;
        }

        return new User($login_user->getAuthIdentifier());
    }
}

<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\ApiClientRepository;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Cache\RateLimiter;

/**
 * admin/oauth/token is called without login:
 * - an API key is taken only with the id and secret of its own client,
 * - wrong passwords are limited as on the login page (per user name and address).
 */
class OAuthTokenGuardTest extends ApiTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.throttle' => true, 'exment.max_attempts' => 3, 'exment.decay_minutes' => 60]);
        app(RateLimiter::class)->clear('oauth-password|admin|127.0.0.1');
    }

    protected function tearDown(): void
    {
        app(RateLimiter::class)->clear('oauth-password|admin|127.0.0.1');
        parent::tearDown();
    }

    public function testAnApiKeyIsNotTakenWithTheSecretOfAnotherClient(): void
    {
        // another API key client, with its own key
        $other = (new ApiClientRepository())->createApiKey(1, 'oauth guard other', 'http://localhost');
        /** @var ApiClient $other */
        $other = ApiClient::withoutGlobalScope('only_self')->findOrFail($other->id);
        $this->assertNotNull($other->client_api_key);

        [$client_id, $client_secret] = $this->getClientIdAndSecretAndKey();

        $this->post(admin_urls('oauth', 'token'), [
            'grant_type' => 'api_key',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'api_key' => $other->client_api_key->key,
            'scope' => implode(' ', ApiScope::arrays()),
        ])->assertStatus(401);
    }

    public function testTheOwnApiKeyStillGivesAToken(): void
    {
        $this->getApiKey()->assertStatus(200);
    }

    public function testWrongPasswordsAreLimited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->getPasswordToken('admin', 'wrong password ' . $i)->assertStatus(401);
        }
        // the right password is refused too until the time is over
        $response = $this->getPasswordToken('admin', 'adminadmin');
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(exmtrans('login.throttle', 60), $response->json('message'));
    }

    public function testTheRightPasswordGivesAToken(): void
    {
        $this->getPasswordToken('admin', 'wrong password')->assertStatus(401);
        $this->getPasswordToken('admin', 'adminadmin')->assertStatus(200);
    }
}

<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * The Passport routes exment mounts under admin/oauth (RouteOAuthServiceProvider). The json ones
 * (clients, scopes, tokens, personal-access-tokens) answer the user of the bearer token
 * ("adminapi" middleware); authorize is a web route of the logged-in admin.
 */
class PassportApiCoverageTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testScopesListTheExmentApiScopes(): void
    {
        $token = $this->getAdminAccessToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get(admin_urls('oauth', 'scopes'))
            ->assertOk();

        // ExmentServiceProvider::bootPassport() registers every ApiScope with its translated text
        $scopes = collect((array) $response->json())->pluck('description', 'id')->all();
        $this->assertEqualsCanonicalizing(ApiScope::arrays(), array_keys($scopes));
        $this->assertSame(exmtrans('api.scopes.' . ApiScope::VALUE_READ), $scopes[ApiScope::VALUE_READ]);
    }

    public function testClientsAreListedCreatedUpdatedAndRevoked(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->getAdminAccessToken()];
        $adminId = $this->admin()->getAuthIdentifier();

        // TestDataSeeder::createApiSetting() gives the admin the password-grant client the API tests use
        $seeded = ApiClient::withoutGlobalScope('only_self')->where('name', Define::API_FEATURE_TEST)->firstOrFail();
        $this->withHeaders($headers)->get(admin_urls('oauth', 'clients'))
            ->assertOk()
            ->assertJsonFragment(['id' => $seeded->id, 'name' => Define::API_FEATURE_TEST]);

        // a new model answers 201; Passport shows the secret, as client secrets are not hashed
        $created = $this->withHeaders($headers)->post(admin_urls('oauth', 'clients'), [
            'name' => 'Claude coverage passport client',
            'redirect' => 'http://localhost/claude-passport',
        ])->assertCreated()
            ->assertJson([
                'name' => 'Claude coverage passport client',
                'redirect' => 'http://localhost/claude-passport',
                'revoked' => false,
            ]);
        $clientId = $created->json('id');
        $this->assertIsString($clientId);
        $this->assertSame(40, strlen((string) $created->json('secret')));
        $this->assertDatabaseHas('oauth_clients', [
            'id' => $clientId,
            'user_id' => $adminId,
            'name' => 'Claude coverage passport client',
            'revoked' => 0,
        ]);

        $this->withHeaders($headers)->put(admin_urls('oauth', 'clients', $clientId), [
            'name' => 'Claude coverage passport client (put)',
            'redirect' => 'http://localhost/claude-passport-put',
        ])->assertOk()
            ->assertJson([
                'id' => $clientId,
                'name' => 'Claude coverage passport client (put)',
                'redirect' => 'http://localhost/claude-passport-put',
            ]);
        $this->assertDatabaseHas('oauth_clients', [
            'id' => $clientId,
            'name' => 'Claude coverage passport client (put)',
            'redirect' => 'http://localhost/claude-passport-put',
        ]);

        // destroy() revokes the client (and its tokens) instead of deleting the row
        $this->withHeaders($headers)->delete(admin_urls('oauth', 'clients', $clientId))->assertNoContent();
        $this->assertDatabaseHas('oauth_clients', ['id' => $clientId, 'revoked' => 1]);

        $listed = collect((array) $this->withHeaders($headers)->get(admin_urls('oauth', 'clients'))->assertOk()->json())->pluck('id')->all();
        $this->assertNotContains($clientId, $listed);
        $this->assertContains($seeded->id, $listed);
    }

    public function testPersonalAccessTokensAreCreatedListedAndRevoked(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->getAdminAccessToken()];
        $adminId = $this->admin()->getAuthIdentifier();

        // Passport issues personal access tokens through a personal access client, which the
        // operator creates once ("php artisan passport:client --personal"); the test data has none
        app(ClientRepository::class)->createPersonalAccessClient($adminId, 'Claude coverage personal access client', 'http://localhost');

        $created = $this->withHeaders($headers)->post(admin_urls('oauth', 'personal-access-tokens'), [
            'name' => 'Claude coverage token',
            'scopes' => [ApiScope::ME],
        ])->assertOk()
            ->assertJsonPath('token.name', 'Claude coverage token')
            ->assertJsonPath('token.scopes', [ApiScope::ME])
            ->assertJsonPath('token.revoked', false);
        $tokenId = $created->json('token.id');
        $this->assertIsString($tokenId);
        $this->assertSame($tokenId, $this->tokenId((string) $created->json('accessToken')));
        $this->assertDatabaseHas('oauth_access_tokens', [
            'id' => $tokenId,
            'user_id' => $adminId,
            'name' => 'Claude coverage token',
            'revoked' => 0,
        ]);

        $listed = collect((array) $this->withHeaders($headers)->get(admin_urls('oauth', 'personal-access-tokens'))->assertOk()->json())->pluck('id')->all();
        $this->assertContains($tokenId, $listed);

        $this->withHeaders($headers)->delete(admin_urls('oauth', 'personal-access-tokens', $tokenId))->assertNoContent();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $tokenId, 'revoked' => 1]);

        $listed = collect((array) $this->withHeaders($headers)->get(admin_urls('oauth', 'personal-access-tokens'))->assertOk()->json())->pluck('id')->all();
        $this->assertNotContains($tokenId, $listed);
    }

    public function testAuthorizedTokensListAndRevokeAThirdPartyClientsToken(): void
    {
        $passwordToken = $this->getAdminAccessToken();
        $headers = ['Authorization' => "Bearer {$passwordToken}"];

        // the api-key client is neither a password nor a personal access client, so Passport counts
        // the tokens it issues as tokens the user authorized for a third party
        $apiKeyTokenId = $this->tokenId($this->getAdminAccessTokenAsApiKey());
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $apiKeyTokenId, 'revoked' => 0]);

        $listed = collect((array) $this->withHeaders($headers)->get(admin_urls('oauth', 'tokens'))->assertOk()->json())->pluck('id')->all();
        $this->assertContains($apiKeyTokenId, $listed);
        // a password-grant token is first party and not listed
        $this->assertNotContains($this->tokenId($passwordToken), $listed);

        $this->withHeaders($headers)->delete(admin_urls('oauth', 'tokens', $apiKeyTokenId))->assertNoContent();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $apiKeyTokenId, 'revoked' => 1]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $apiKeyTokenId, 'revoked' => 1]);
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['access_token_id' => $apiKeyTokenId, 'revoked' => 0]);

        $listed = collect((array) $this->withHeaders($headers)->get(admin_urls('oauth', 'tokens'))->assertOk()->json())->pluck('id')->all();
        $this->assertNotContains($apiKeyTokenId, $listed);
    }

    // authorize is the consent screen of the authorization-code flow. Passport's
    // AuthorizationController takes the approving user from Auth::guard(config('passport.guard')),
    // "web" by default, while exment logs users in on "admin": a valid request never reaches the
    // consent screen, so these tests check the refusals that come before it.

    public function testAuthorizeRefusesAnUnknownClient(): void
    {
        $this->be($this->admin());

        $this->get(admin_urls('oauth', 'authorize') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => (string) Str::uuid(),
            'redirect_uri' => 'http://localhost/claude-passport',
        ]))->assertStatus(401)
            ->assertJson(['error' => 'invalid_client']);
    }

    public function testApprovingWithAnotherAuthTokenIsRefused(): void
    {
        $this->be($this->admin());

        // the auth token guards the approval like a CSRF token: a different one drops the request
        $this->withSession(['authToken' => 'claude-session-auth-token'])
            ->post(admin_urls('oauth', 'authorize'), ['auth_token' => 'claude-other-auth-token'])
            ->assertForbidden()
            ->assertSessionMissing('authToken');
    }

    public function testDenyingWithAnotherAuthTokenIsRefused(): void
    {
        $this->be($this->admin());

        $this->withSession(['authToken' => 'claude-session-auth-token'])
            ->delete(admin_urls('oauth', 'authorize'), ['auth_token' => 'claude-other-auth-token'])
            ->assertForbidden()
            ->assertSessionMissing('authToken');
    }

    private function admin(): LoginUser
    {
        return LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    /**
     * The id Passport stored for an access token: the jti claim of the JWT.
     */
    private function tokenId(string $jwt): string
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'an access token is a JWT');
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $jti = is_array($claims) ? ($claims['jti'] ?? null) : null;
        if (!is_string($jti)) {
            $this->fail('the access token has no jti claim');
        }

        return $jti;
    }
}

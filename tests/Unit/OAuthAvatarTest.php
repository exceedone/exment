<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Services\Login\OAuth\OAuthService;
use Exceedone\Exment\Services\Login\OAuth\OAuthUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * The avatar of a user who signs in with OAuth (ISS02869): from a provider that reads it
 * itself (Auth\ProviderAvatar, as a program file for microsoft/graph), or from the avatar
 * URL of the profile, fetched only from an address Exment may reach.
 */
class OAuthAvatarTest extends UnitTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** a 1x1 PNG */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var mixed the requests the mock client got (Guzzle's history) */
    protected $history = [];

    /** @var mixed the token a provider was asked with */
    protected $askedToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Storage::fake(config('admin.upload.disk', 'admin'));
        LoginService::$avatarHttpClient = null;
        $this->history = [];
    }

    protected function tearDown(): void
    {
        LoginService::$avatarHttpClient = null;
        parent::tearDown();
    }

    /**
     * A new user signing in with OAuth (made at the login: sso_jit), with an avatar URL or none.
     */
    protected function oauthUser(?string $avatar, string $code): OAuthUser
    {
        LoginSetting::create([
            'login_view_name' => 'avatar test',
            'login_type' => 'oauth',
            'active_flg' => 1,
            'options' => ['oauth_provider_type' => 'google', 'mapping_user_column' => 'email', 'sso_jit' => '1', 'update_user_info' => '1'],
        ]);
        $provider_user = (new SocialiteUser())->map(['id' => $code, 'name' => 'avatar ' . $code, 'email' => $code . '@avatar.foobar.test', 'avatar' => $avatar]);
        $provider_user->setToken('token-' . $code);
        return OAuthUser::with('google', $provider_user, true);
    }

    /**
     * @return array<int, mixed>
     */
    protected function requests(): array
    {
        return is_array($this->history) ? array_values($this->history) : [];
    }

    protected function mockClient(Response ...$responses): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $stack->push(Middleware::history($history));
        $this->history = &$history;
        LoginService::$avatarHttpClient = new Client(['handler' => $stack]);
    }

    /**
     * A provider that reads the avatar itself.
     */
    protected function provider(?string $contents): ProviderAvatar
    {
        $onToken = function ($token) {
            $this->askedToken = $token;
        };
        return new class ($contents, $onToken) implements ProviderAvatar {
            /** @var string|null */
            protected $contents;
            /** @var \Closure */
            protected $onToken;

            public function __construct(?string $contents, \Closure $onToken)
            {
                $this->contents = $contents;
                $this->onToken = $onToken;
            }

            public function getAvatar($token = null): ?string
            {
                ($this->onToken)($token);
                return $this->contents;
            }
        };
    }

    public function testTheProviderOfTheLoginGivesTheAvatar(): void
    {
        $user = $this->oauthUser(null, 'avatar_provider');
        $provider = $this->provider(base64_decode(static::PNG));
        $login_user = LoginService::executeLogin(request(), $user, $provider);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        // the provider was asked, with the token of the login (it was given null before)
        $this->assertSame('token-avatar_provider', $this->askedToken);
        $this->assertNotNull($login_user->avatar);
        $this->assertTrue(Storage::disk(config('admin.upload.disk', 'admin'))->exists($login_user->avatar));
    }

    public function testWhatIsNotAnImageIsNotTaken(): void
    {
        $user = $this->oauthUser(null, 'avatar_not_image');
        $login_user = LoginService::executeLogin(request(), $user, $this->provider('<html>not an image</html>'));
        $this->assertNull($login_user->avatar);
    }

    public function testTheAvatarUrlOfTheProfile(): void
    {
        config(['exment.notify_webhook_allow_hosts' => 'avatar.example.test']);
        $this->mockClient(new Response(200, ['Content-Type' => 'image/png'], base64_decode(static::PNG)));
        $user = $this->oauthUser('https://avatar.example.test/photo.png', 'avatar_url');
        $login_user = LoginService::executeLogin(request(), $user);
        $this->assertCount(1, $this->requests());
        $this->assertSame('https://avatar.example.test/photo.png', strval($this->requests()[0]['request']->getUri()));
        $this->assertNotNull($login_user->avatar);
    }

    public function testAPrivateAddressIsNotFetched(): void
    {
        $this->mockClient(new Response(200, [], base64_decode(static::PNG)));
        foreach (['http://127.0.0.1/photo.png', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.5/photo.png', 'file:///etc/passwd'] as $i => $url) {
            $this->assertNull(LoginService::downloadAvatar($url), $url);
        }
        $this->assertCount(0, $this->requests(), 'nothing was asked');

        $user = $this->oauthUser('http://127.0.0.1/photo.png', 'avatar_private');
        $login_user = LoginService::executeLogin(request(), $user);
        $this->assertNull($login_user->avatar);
        $this->assertCount(0, $this->requests());
    }

    public function testAFailedFetchDoesNotStopTheLogin(): void
    {
        config(['exment.notify_webhook_allow_hosts' => 'avatar.example.test']);
        // the mock has nothing to answer: the client throws
        $this->mockClient();
        $user = $this->oauthUser('https://avatar.example.test/photo.png', 'avatar_fail');
        $login_user = LoginService::executeLogin(request(), $user);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        $this->assertNull($login_user->avatar);
    }

    public function testAProviderThatDoesNotReadTheAvatarIsNotGiven(): void
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'google avatar',
            'login_type' => 'oauth',
            'active_flg' => 1,
            'options' => ['oauth_provider_type' => 'google', 'oauth_client_id' => 'x', 'oauth_client_secret' => 'y', 'mapping_user_column' => 'email'],
        ]);
        $this->assertNull(OAuthService::avatarProvider($setting));
        $this->assertNull(OAuthService::avatarProvider(null));
    }
}

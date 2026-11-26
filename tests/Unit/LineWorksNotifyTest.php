<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LineWorks\LineWorks;
use Exceedone\Exment\Services\LineWorks\LineWorksClient;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The notifications to LINE WORKS (ISS00114), with HTTP fakes: there is no LINE WORKS tenant to
 * test with, so the requests are checked against the LINE WORKS API 2.0 as documented (the token
 * of the service account, a message of the bot to a person or to a channel).
 */
class LineWorksNotifyTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const AUTH = 'https://auth.lineworks.test/oauth2/v2.0/token';
    protected const API = 'https://api.lineworks.test/v1.0';

    /** @var string */
    protected $privateKey = '';

    /** @var string */
    protected $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = strval($pem);
        $this->publicKey = strval(openssl_pkey_get_details($key)['key'] ?? '');

        config([
            'exment.lineworks.auth_url' => static::AUTH,
            'exment.lineworks.api_url' => static::API,
            // the fake hosts do not resolve: allowed by name, as an administrator allows a host
            'exment.notify_webhook_allow_hosts' => 'auth.lineworks.test,api.lineworks.test',
            'exment.notify_webhook_retry_seconds' => [0, 0],
            'queue.default' => 'sync',
        ]);
        Features::save([Features::LINE_WORKS_NOTIFY => ['enabled' => true]]);
        System::system_lineworks_client_id('client-1');
        System::system_lineworks_client_secret('secret-1');
        System::system_lineworks_service_account('sa-1@example');
        System::system_lineworks_private_key($this->privateKey);
        System::system_lineworks_bot_id('bot 1');
        System::system_lineworks_user_column(null);
        System::clearCache();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    protected function fakeOk(): void
    {
        Http::fake([
            static::AUTH => Http::response(['access_token' => 'token-1', 'expires_in' => 86400], 200),
            static::API . '/*' => Http::response([], 201),
        ]);
    }

    protected function admin(): \Exceedone\Exment\Model\CustomValue
    {
        /** @var LoginUser $login */
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        return $login->base_user;
    }

    public function testTheAssertionIsSignedByTheServiceAccount(): void
    {
        $credentials = LineWorks::credentials();
        $this->assertNotNull($credentials);
        $jwt = (new LineWorksClient($credentials))->assertion(1764140000);
        [$header, $claims, $signature] = explode('.', $jwt);
        $decode = fn ($part) => json_decode(strval(base64_decode(strtr($part, '-_', '+/'))), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $decode($header));
        $this->assertSame(['iss' => 'client-1', 'sub' => 'sa-1@example', 'iat' => 1764140000, 'exp' => 1764143600], $decode($claims));
        $raw = base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', (4 - strlen($signature) % 4) % 4));
        $this->assertSame(1, openssl_verify($header . '.' . $claims, strval($raw), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testAPersonGetsTheMessageOfTheBot(): void
    {
        $this->fakeOk();
        $user = $this->admin();
        $job = NotifyService::notifyLineWorksPersonal(['user' => $user, 'subject' => 'Subject 1', 'body' => "Line A\nLine B\n\nPara 2", 'url' => 'https://exment.test/admin/data/x/1']);
        $this->assertNotNull($job);

        $email = strval($user->getValue('email'));
        Http::assertSent(function (Request $request) {
            return $request->url() === static::AUTH
                && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $request['client_id'] === 'client-1' && $request['client_secret'] === 'secret-1'
                && $request['scope'] === 'bot' && substr_count(strval($request['assertion']), '.') === 2;
        });
        Http::assertSent(function (Request $request) use ($email) {
            return $request->url() === static::API . '/bots/bot%201/users/' . rawurlencode($email) . '/messages'
                && $request->hasHeader('Authorization', 'Bearer token-1')
                && $request['content']['type'] === 'text'
                && $request['content']['text'] === "Subject 1\n\nLine A\nLine B\n\nPara 2\n\nhttps://exment.test/admin/data/x/1";
        });
    }

    public function testAChannelAndTheTokenKept(): void
    {
        $this->fakeOk();
        NotifyService::notifyLineWorks(['subject' => 'S', 'body' => 'B'], ['lineworks_channel_id' => 'ch-123']);
        NotifyService::notifyLineWorks(['subject' => 'S2', 'body' => 'B2'], ['lineworks_channel_id' => 'ch-123']);
        Http::assertSent(fn (Request $r) => $r->url() === static::API . '/bots/bot%201/channels/ch-123/messages');
        $tokens = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === static::AUTH)->count();
        $this->assertSame(1, $tokens, 'the token is kept in the cache');

        // no channel: nothing
        $this->assertNull(NotifyService::notifyLineWorks(['subject' => 'S'], ['lineworks_channel_id' => '']));
    }

    public function testARefusedTokenIsMadeAgainOnce(): void
    {
        Http::fake([
            static::AUTH => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 86400])->push(['access_token' => 'new', 'expires_in' => 86400]),
            static::API . '/*' => Http::sequence()->push([], 401)->push([], 201),
        ]);
        NotifyService::notifyLineWorks(['subject' => 'S', 'body' => 'B'], ['lineworks_channel_id' => 'ch-1']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/channels/ch-1/') && $r->hasHeader('Authorization', 'Bearer new'));
        $this->assertSame(0, NotifyNavbar::query()->where('notify_subject', 'like', '%LINE WORKS%')->count());
    }

    public function testNotSentAtLastIsABell(): void
    {
        Http::fake([
            static::AUTH => Http::response(['access_token' => 'token-1', 'expires_in' => 86400], 200),
            static::API . '/*' => Http::response([], 500),
        ]);
        $before = NotifyNavbar::query()->count();
        NotifyService::notifyLineWorks(['subject' => 'S', 'body' => 'B'], ['lineworks_channel_id' => 'ch-9']);
        $messages = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/messages'))->count();
        $this->assertSame(3, $messages, 'tried 3 times');
        $this->assertGreaterThan($before, NotifyNavbar::query()->count(), 'a bell');
        $bell = NotifyNavbar::query()->orderByDesc('id')->first();
        $this->assertStringContainsString('ch-9', strval($bell->notify_body ?? ''));
        $this->assertStringNotContainsString('secret-1', strval($bell->notify_body ?? ''));
    }

    public function testAnAddressNotAllowedIsNotPosted(): void
    {
        config(['exment.lineworks.api_url' => 'https://127.0.0.1/v1.0']);
        $this->fakeOk();
        NotifyService::notifyLineWorks(['subject' => 'S', 'body' => 'B'], ['lineworks_channel_id' => 'ch-1']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1'));
    }

    public function testOffWhileTheFeatureOrTheAppIsNot(): void
    {
        $this->fakeOk();
        Features::save([Features::LINE_WORKS_NOTIFY => ['enabled' => false]]);
        $this->assertFalse(LineWorks::enabled());
        $this->assertNull(NotifyService::notifyLineWorks(['subject' => 'S'], ['lineworks_channel_id' => 'ch-1']));
        $this->assertArrayNotHasKey(NotifyAction::LINE_WORKS, NotifyAction::selectOptions());
        $this->assertArrayNotHasKey(NotifyAction::LINE_WORKS_PERSONAL, NotifyAction::selectOptions());

        Features::save([Features::LINE_WORKS_NOTIFY => ['enabled' => true]]);
        $this->assertArrayHasKey(NotifyAction::LINE_WORKS, NotifyAction::selectOptions());
        System::system_lineworks_bot_id(null);
        System::clearCache();
        $this->assertFalse(LineWorks::enabled(), 'the app not set');
        Http::assertNothingSent();
    }

    public function testTheSecretsAreKeptEncryptedAndTheKindsOfAction(): void
    {
        $raw = \DB::table('systems')->where('system_name', 'system_lineworks_client_secret')->value('system_value');
        $this->assertNotSame('secret-1', $raw);
        $this->assertSame('secret-1', System::system_lineworks_client_secret());
        $this->assertStringNotContainsString('PRIVATE KEY', strval(\DB::table('systems')->where('system_name', 'system_lineworks_private_key')->value('system_value')));

        $this->assertTrue(NotifyAction::isChatMessage(NotifyAction::LINE_WORKS));
        $this->assertTrue(NotifyAction::isUserTarget(NotifyAction::LINE_WORKS_PERSONAL));
        $this->assertFalse(NotifyAction::isUserTarget(NotifyAction::LINE_WORKS));

        // the text at most 2000 characters, the link kept
        $content = LineWorks::content('S', str_repeat('a', 3000), 'https://exment.test/x');
        $this->assertLessThanOrEqual(LineWorks::MAX_TEXT, mb_strlen($content['text']));
        $this->assertStringEndsWith('https://exment.test/x', $content['text']);
    }
}

<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\MailOAuth\MailOAuth;
use Exceedone\Exment\Services\MailOAuth\XOAuth2TokenAuthenticator;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Mail with OAuth 2.0 (ISS02913): the tokens of Microsoft 365 and Google, kept until they expire,
 * the XOAUTH2 login, the mailer used, one bell to the administrators when there is no token, and
 * nothing while the feature is off.
 */
class MailOAuthTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        \DB::beginTransaction();
        Cache::forget('exment_mail_oauth_failure_told');
    }

    protected function tearDown(): void
    {
        MailOAuth::forgetToken();
        \DB::rollBack();
        System::clearCache();
        parent::tearDown();
    }

    protected function use(string $provider): void
    {
        Features::save([Features::MAIL_OAUTH => ['enabled' => true]]);
        System::system_mail_auth($provider);
        System::system_mail_oauth_tenant('contoso.onmicrosoft.com');
        System::system_mail_oauth_client_id('the-client');
        System::system_mail_oauth_client_secret('the-secret');
        System::system_mail_username('box@example.com');
        System::clearCache();
        MailOAuth::forgetToken();
    }

    public function testNothingWhileTheFeatureIsOff(): void
    {
        $this->use(MailOAuth::MICROSOFT);
        Features::save([Features::MAIL_OAUTH => ['enabled' => false]]);
        System::clearCache();

        config(['mail.default' => 'smtp']);
        $this->assertNull(MailOAuth::provider());
        MailOAuth::applyConfig();
        $this->assertSame('smtp', config('mail.default'));
    }

    public function testTheTokenOfMicrosoft365(): void
    {
        $this->use(MailOAuth::MICROSOFT);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'token-m', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);

        $this->assertSame('token-m', MailOAuth::accessToken());
        $this->assertSame('token-m', MailOAuth::accessToken(), 'kept');
        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token'
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'the-client'
                && $request['client_secret'] === 'the-secret'
                && $request['scope'] === 'https://outlook.office365.com/.default';
        });
    }

    public function testTheTokenOfGoogle(): void
    {
        $this->use(MailOAuth::GOOGLE);
        System::system_mail_oauth_refresh_token('the-refresh');
        System::clearCache();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'token-g', 'expires_in' => 3599])]);

        $this->assertSame('token-g', MailOAuth::accessToken());
        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === MailOAuth::GOOGLE_TOKEN_URL
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'the-refresh'
                && $request['client_id'] === 'the-client';
        });
    }

    public function testConnectingGoogleKeepsTheRefreshToken(): void
    {
        $this->use(MailOAuth::GOOGLE);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'new-refresh', 'expires_in' => 3599])]);

        $url = MailOAuth::googleAuthUrl('https://exment.example.com/admin/mail_oauth_setting/google/callback', 'st');
        parse_str(strval(parse_url($url, PHP_URL_QUERY)), $query);
        $this->assertSame('https://mail.google.com/', $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('st', $query['state']);

        MailOAuth::connectGoogle('the-code', 'https://exment.example.com/admin/mail_oauth_setting/google/callback');
        System::clearCache();
        $this->assertSame('new-refresh', System::system_mail_oauth_refresh_token());
        // kept encrypted
        $stored = \DB::table('systems')->where('system_name', 'system_mail_oauth_refresh_token')->value('system_value');
        $this->assertNotSame('new-refresh', $stored);
        Http::assertSent(fn (HttpRequest $request) => $request['grant_type'] === 'authorization_code' && $request['code'] === 'the-code');
    }

    public function testTheLoginOfXOAuth2(): void
    {
        $this->use(MailOAuth::MICROSOFT);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'token-m', 'expires_in' => 3599])]);

        $transport = new class ('localhost', 25) extends EsmtpTransport {
            /** @var array<int, string> */
            public array $commands = [];

            public function executeCommand(string $command, array $codes): string
            {
                $this->commands[] = $command;
                return '235 ok';
            }
        };
        $transport->setUsername('box@example.com');
        (new XOAuth2TokenAuthenticator())->authenticate($transport);

        $this->assertSame(['AUTH XOAUTH2 ' . base64_encode("user=box@example.com\1auth=Bearer token-m\1\1") . "\r\n"], $transport->commands);
    }

    public function testTheMailerOfOAuth(): void
    {
        $this->use(MailOAuth::MICROSOFT);
        config(['mail.default' => 'smtp']);
        MailOAuth::applyConfig();

        $this->assertSame(MailOAuth::MAILER, config('mail.default'));
        $this->assertSame('smtp.office365.com', config('mail.mailers.' . MailOAuth::MAILER . '.host'));
        $this->assertSame(587, config('mail.mailers.' . MailOAuth::MAILER . '.port'));
        $this->assertSame('box@example.com', config('mail.mailers.' . MailOAuth::MAILER . '.username'));

        $transport = app('mail.manager')->mailer(MailOAuth::MAILER)->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('box@example.com', $transport->getUsername());
    }

    public function testNoTokenTellsTheAdministratorsOnce(): void
    {
        $this->use(MailOAuth::MICROSOFT);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.'], 401)]);
        $before = NotifyNavbar::withoutGlobalScopes()->count();

        foreach ([1, 2] as $i) {
            try {
                MailOAuth::accessToken();
                $this->fail('no token');
            } catch (TransportException $ex) {
                $this->assertStringContainsString('invalid_client', $ex->getMessage());
                $this->assertStringNotContainsString('the-secret', $ex->getMessage());
            }
        }

        $admins = array_unique((array)System::system_admin_users());
        $this->assertNotEmpty($admins);
        $this->assertSame(count($admins), NotifyNavbar::withoutGlobalScopes()->count() - $before, 'one bell each, not one each failure');
    }
}

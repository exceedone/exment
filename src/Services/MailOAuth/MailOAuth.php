<?php

namespace Exceedone\Exment\Services\MailOAuth;

use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Mail sent with OAuth 2.0 (SMTP XOAUTH2, ISS02913): Microsoft 365 is stopping the SMTP login with
 * a user name and a password.
 *
 * - Microsoft 365: the application of the tenant (client credentials). The application has the
 *   permission SMTP.SendAsApp, and Exchange lets it send as the mailbox (the user name).
 * - Google Workspace / Gmail: a client of Google Cloud; a system administrator connects the
 *   mailbox once on the setting page (SystemMailOAuthController), and its refresh token is kept.
 *
 * The access token is asked for when a connection is opened, and kept until a minute before it
 * expires (a queue worker runs for days). When it cannot be had, the mail fails as a mail does
 * (MailSendJob tells), and the system administrators get a bell, once an hour.
 *
 * Off until a system administrator turns it on (Features::MAIL_OAUTH).
 */
class MailOAuth
{
    public const MICROSOFT = 'microsoft';
    public const GOOGLE = 'google';

    /** the mailer of config/mail.php made here */
    public const MAILER = 'exment_oauth';

    public const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const GOOGLE_SCOPE = 'https://mail.google.com/';
    public const MICROSOFT_SCOPE = 'https://outlook.office365.com/.default';

    protected const CACHE_KEY = 'exment_mail_oauth_token_';
    protected const TOLD_KEY = 'exment_mail_oauth_failure_told';

    /**
     * The provider used, or null (the user name and the password).
     */
    public static function provider(): ?string
    {
        if (!Features::enabled(Features::MAIL_OAUTH)) {
            return null;
        }
        $provider = strval(System::system_mail_auth());
        return in_array($provider, [static::MICROSOFT, static::GOOGLE], true) ? $provider : null;
    }

    /**
     * Use the mailer of OAuth (Middleware\Initialize, after the mail settings of the page).
     */
    public static function applyConfig(): void
    {
        $provider = static::provider();
        if (!isset($provider)) {
            return;
        }
        [$host, $port] = $provider === static::MICROSOFT ? ['smtp.office365.com', 587] : ['smtp.gmail.com', 587];
        $host = is_nullorempty(System::system_mail_host()) ? $host : strval(System::system_mail_host());
        $port = is_nullorempty(System::system_mail_port()) ? $port : intval(System::system_mail_port());

        Config::set('mail.mailers.' . static::MAILER, [
            'transport' => static::MAILER,
            'host' => $host,
            'port' => $port,
            'encryption' => System::system_mail_encryption(),
            'username' => System::system_mail_username(),
            'timeout' => 30,
        ]);
        Config::set('mail.default', static::MAILER);
    }

    /**
     * The transport of the mailer (ExmentServiceProvider: Mail::extend).
     *
     * @param array<string, mixed> $config
     */
    public static function transport(array $config): EsmtpTransport
    {
        $port = intval($config['port'] ?? 587);
        $encryption = strtolower(strval($config['encryption'] ?? ''));
        // 465: TLS from the start; else STARTTLS when the server offers it (Office 365, Gmail do)
        $tls = $port === 465 || in_array($encryption, ['ssl', 'smtps'], true);
        $transport = new EsmtpTransport(strval($config['host'] ?? 'localhost'), $port, $tls);
        $transport->setUsername(strval($config['username'] ?? ''));
        $transport->setAuthenticators([new XOAuth2TokenAuthenticator()]);
        $stream = $transport->getStream();
        if (isset($config['timeout']) && method_exists($stream, 'setTimeout')) {
            $stream->setTimeout(floatval($config['timeout']));
        }
        return $transport;
    }

    /**
     * An access token for the mailbox.
     *
     * @throws TransportException
     */
    public static function accessToken(): string
    {
        $provider = strval(System::system_mail_auth());
        $clientId = strval(System::system_mail_oauth_client_id());
        $key = static::CACHE_KEY . md5($provider . '|' . System::system_mail_oauth_tenant() . '|' . $clientId . '|' . System::system_mail_username());
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            [$token, $expires] = static::requestToken($provider);
        } catch (\Throwable $ex) {
            static::tellFailure($ex->getMessage());
            throw new TransportException('[Exment] no OAuth token for the mail: ' . $ex->getMessage(), 0, $ex);
        }
        // kept until a minute before it expires
        Cache::put($key, $token, max(60, $expires - 60));
        return $token;
    }

    /**
     * Forget the token kept (the settings changed, or the server refused it).
     */
    public static function forgetToken(): void
    {
        $key = static::CACHE_KEY . md5(strval(System::system_mail_auth()) . '|' . System::system_mail_oauth_tenant() . '|' . System::system_mail_oauth_client_id() . '|' . System::system_mail_username());
        Cache::forget($key);
    }

    /**
     * @return array{0: string, 1: int} the token and its seconds
     */
    protected static function requestToken(string $provider): array
    {
        $clientId = strval(System::system_mail_oauth_client_id());
        $secret = strval(System::system_mail_oauth_client_secret());
        if ($clientId === '' || $secret === '') {
            throw new \RuntimeException(exmtrans('system.mail_oauth.not_set'));
        }

        if ($provider === static::MICROSOFT) {
            $tenant = strval(System::system_mail_oauth_tenant());
            if ($tenant === '' || !preg_match('/\A[A-Za-z0-9.\-]+\z/', $tenant)) {
                throw new \RuntimeException(exmtrans('system.mail_oauth.not_set'));
            }
            $response = Http::asForm()->timeout(15)->post('https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => static::MICROSOFT_SCOPE,
            ]);
        } elseif ($provider === static::GOOGLE) {
            $refresh = strval(System::system_mail_oauth_refresh_token());
            if ($refresh === '') {
                throw new \RuntimeException(exmtrans('system.mail_oauth.not_connected'));
            }
            $response = Http::asForm()->timeout(15)->post(static::GOOGLE_TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'refresh_token' => $refresh,
            ]);
        } else {
            throw new \RuntimeException('unknown provider');
        }

        $token = $response->json('access_token');
        if (!$response->successful() || !is_string($token) || $token === '') {
            // the error code and its text, never the secret
            $error = trim(strval($response->json('error')) . ' ' . strval($response->json('error_description')));
            throw new \RuntimeException('HTTP ' . $response->status() . ($error !== '' ? ': ' . Str::limit($error, 300) : ''));
        }
        return [$token, intval($response->json('expires_in') ?? 3600)];
    }

    /**
     * The page of Google to connect the mailbox.
     */
    public static function googleAuthUrl(string $redirectUri, string $state): string
    {
        return static::GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id' => strval(System::system_mail_oauth_client_id()),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => static::GOOGLE_SCOPE,
            // a refresh token, each time it is connected
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'login_hint' => strval(System::system_mail_username()),
        ]);
    }

    /**
     * The code Google gave back, for the refresh token kept.
     *
     * @throws \RuntimeException
     */
    public static function connectGoogle(string $code, string $redirectUri): void
    {
        $response = Http::asForm()->timeout(15)->post(static::GOOGLE_TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => strval(System::system_mail_oauth_client_id()),
            'client_secret' => strval(System::system_mail_oauth_client_secret()),
            'redirect_uri' => $redirectUri,
        ]);
        $refresh = $response->json('refresh_token');
        if (!$response->successful() || !is_string($refresh) || $refresh === '') {
            $error = trim(strval($response->json('error')) . ' ' . strval($response->json('error_description')));
            throw new \RuntimeException('HTTP ' . $response->status() . ($error !== '' ? ': ' . Str::limit($error, 300) : ''));
        }
        System::system_mail_oauth_refresh_token($refresh);
        System::system_mail_oauth_connected_at(\Carbon\Carbon::now()->toDateTimeString());
        static::forgetToken();
        Cache::forget(static::TOLD_KEY);
    }

    /**
     * A bell to the system administrators, once an hour: no mail is sent until it is mended.
     */
    protected static function tellFailure(string $error): void
    {
        \Log::error('[Exment] no OAuth token for the mail (' . strval(System::system_mail_auth()) . '): ' . $error);
        if (!Cache::add(static::TOLD_KEY, 1, 3600)) {
            return;
        }
        foreach (array_unique(array_map(fn ($id) => intval($id), (array)(System::system_admin_users() ?? []))) as $userId) {
            try {
                $bell = new NotifyNavbar();
                $bell->notify_id = 0;
                $bell->target_user_id = $userId;
                $bell->notify_subject = Str::limit(exmtrans('system.mail_oauth.failed_subject'), 190);
                $bell->notify_body = Str::limit(exmtrans('system.mail_oauth.failed_body', $error), 1990);
                $bell->save();
            } catch (\Throwable $ex) {
                \Log::error('[Exment] the bell of the mail OAuth was not saved: ' . $ex->getMessage());
            }
        }
    }
}

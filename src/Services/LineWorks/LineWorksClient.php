<?php

namespace Exceedone\Exment\Services\LineWorks;

use Exceedone\Exment\Services\WebhookUrlGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The LINE WORKS API 2.0 for the notifications (ISS00114): the access token of the app (the
 * service account, a JWT signed with its private key, RS256), kept in the cache until a little
 * before it ends, and a message of the bot to a person or to a channel.
 *
 *   POST {auth}/oauth2/v2.0/token                              the token (scope bot)
 *   POST {api}/v1.0/bots/{botId}/users/{userId}/messages       to a person (the id or the mail address)
 *   POST {api}/v1.0/bots/{botId}/channels/{channelId}/messages to a channel
 *
 * The addresses are checked as a webhook's are (WebhookUrlGuard: https, not the server itself
 * nor a private range), each time, the connection goes to the address checked, and a redirect is
 * not followed. The secrets are never written to a log.
 */
class LineWorksClient
{
    public const SCOPE = 'bot';

    /**
     * @param array{client_id: string, client_secret: string, service_account: string, private_key: string, bot_id: string} $credentials
     */
    public function __construct(protected array $credentials)
    {
    }

    public static function authUrl(): string
    {
        return rtrim(strval(config('exment.lineworks.auth_url', 'https://auth.worksmobile.com/oauth2/v2.0/token')), '/');
    }

    public static function apiUrl(): string
    {
        return rtrim(strval(config('exment.lineworks.api_url', 'https://www.worksapis.com/v1.0')), '/');
    }

    protected function cacheKey(): string
    {
        return 'exment_lineworks_token:' . substr(hash('sha256', $this->credentials['client_id'] . "\n" . $this->credentials['service_account']), 0, 24);
    }

    /**
     * Forget the token kept (it was refused: made again at the next call).
     */
    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * The JWT of the service account.
     */
    public function assertion(?int $now = null): string
    {
        $now = $now ?? time();
        $encode = function (array $part): string {
            return rtrim(strtr(base64_encode(strval(json_encode($part))), '+/', '-_'), '=');
        };
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode([
            'iss' => $this->credentials['client_id'],
            'sub' => $this->credentials['service_account'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $key = openssl_pkey_get_private($this->credentials['private_key']);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new LineWorksException('The private key of the LINE WORKS app cannot sign (check it).', false);
        }
        return $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * The access token, from the cache or made.
     *
     * @throws LineWorksException
     */
    public function token(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $response = $this->request(static::authUrl(), function ($http, $url) {
            return $http->asForm()->post($url, [
                'assertion' => $this->assertion(),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'client_id' => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
                'scope' => static::SCOPE,
            ]);
        });
        if (!$response->successful()) {
            $status = $response->status();
            throw new LineWorksException('The token of LINE WORKS was refused: HTTP ' . $status, $status >= 500 || in_array($status, [408, 429], true));
        }
        $token = strval($response->json('access_token'));
        if ($token === '') {
            throw new LineWorksException('LINE WORKS gave no access token.', true);
        }
        $seconds = max(60, intval($response->json('expires_in', 3600)) - 300);
        Cache::put($this->cacheKey(), $token, $seconds);
        return $token;
    }

    /**
     * A message of the bot. $to is "users/{id}" or "channels/{id}".
     *
     * @param array<string, mixed> $content the "content" of the message
     * @throws LineWorksException
     */
    public function send(string $to, array $content): void
    {
        $url = static::apiUrl() . '/bots/' . rawurlencode($this->credentials['bot_id']) . '/' . $to . '/messages';
        $post = function (string $token) use ($url, $content) {
            return $this->request($url, function ($http, $url) use ($token, $content) {
                return $http->withToken($token)->asJson()->post($url, ['content' => $content]);
            });
        };
        $response = $post($this->token());
        if ($response->status() === 401) {
            // the token kept was ended or taken back: a new one, once
            $this->forgetToken();
            $response = $post($this->token());
        }
        if (!$response->successful()) {
            $status = $response->status();
            throw new LineWorksException('LINE WORKS answered HTTP ' . $status, $status >= 500 || in_array($status, [408, 429], true));
        }
    }

    /**
     * One request to an address of LINE WORKS, checked, to the address checked, not redirected.
     *
     * @param \Closure(\Illuminate\Http\Client\PendingRequest, string): \Illuminate\Http\Client\Response $call
     * @throws LineWorksException
     */
    protected function request(string $url, \Closure $call): \Illuminate\Http\Client\Response
    {
        $ips = preg_match('#\Ahttps://#i', $url) ? WebhookUrlGuard::allowedIps($url) : null;
        if ($ips === null) {
            throw new LineWorksException('The address of LINE WORKS is not allowed: ' . strval(parse_url($url, PHP_URL_HOST)), false);
        }
        $http = Http::timeout(15)->connectTimeout(5)->withOptions(static::connectOptions($url, $ips));
        try {
            return $call($http, $url);
        } catch (LineWorksException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LineWorksException('LINE WORKS could not be reached (' . strval(parse_url($url, PHP_URL_HOST)) . '): ' . get_class($e), true);
        }
    }

    /**
     * Not redirected, and connected to the address checked (as JsonWebhookJob).
     *
     * @param array<string> $ips
     * @return array<string, mixed>
     */
    public static function connectOptions(string $url, array $ips): array
    {
        $options = ['allow_redirects' => false];
        $parts = parse_url($url);
        $host = trim(strval($parts['host'] ?? ''), '[]');
        if (empty($ips) || filter_var($host, FILTER_VALIDATE_IP) || !defined('CURLOPT_RESOLVE')) {
            return $options;
        }
        $port = intval($parts['port'] ?? 443);
        $ip = $ips[0];
        $options['curl'] = [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)]];
        return $options;
    }
}

<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Auth\UserApiKeyToken;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\UserApiKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The API keys of the users (ISS00437). A user whom the administrators allow (the feature
 * "api_keys" of Feature management, with its role groups) makes keys of their own; a key
 * has the permissions of the user, and no more:
 *
 * - it is shown once, when it is made; only its sha256 is kept
 * - it has an end date (90 days by default, a year at most)
 * - its scopes (as "read only") and, if chosen, the tables it may use
 * - the IP addresses it may come from, besides the API IP filter of the site
 * - a client certificate it needs: the fingerprint the front server (nginx, ALB) gives,
 *   taken only from a trusted proxy (ADMIN_TRUST_PROXY_IPS)
 * - when and from where it was last used
 *
 * It stops at once when it is stopped (revoked), when the user or the login of the user is
 * removed, when the user leaves the role groups of the feature, or when the feature is off.
 *
 * It is sent as "Authorization: Bearer exk_...", in place of an OAuth access token.
 */
class UserApiKeys
{
    public const PREFIX = 'exk_';
    public const DEFAULT_DAYS = 90;
    public const MAX_DAYS = 365;

    /** The routes a key limited to some tables may still use (they read no table). */
    protected const ROUTES_WITHOUT_TABLE = ['ApiController@me', 'ApiController@avatar', 'ApiController@version'];

    /** The routes that check the tables of a key themselves (allowsTable()), as the MCP server. */
    protected const ROUTES_CHECKING_TABLES = ['McpController@handle'];

    /** The routes of the files (checked by the table the file belongs to). */
    protected const FILE_ROUTES = ['FileController@downloadApi', 'FileController@downloadTableApi', 'FileController@deleteApi', 'FileController@deleteTableApi'];

    /** The routes of a table that read other tables too (not for a key limited to some tables). */
    protected const ROUTES_READING_OTHER_TABLES = [
        'ApiDataController@relatedLinkage', 'ApiDataController@dataSelect', 'ApiDataController@columnData',
        'ApiDataController@viewDataList', 'ApiDataController@viewDataFind',
    ];

    /** Why the last authenticate() refused (for the log and the tests; never sent to the client). */
    public static ?string $lastRefusal = null;

    public static function looksLikeKey(?string $token): bool
    {
        return is_string($token) && str_starts_with($token, static::PREFIX);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * The scopes a key may have.
     *
     * @return array<int, string>
     */
    public static function availableScopes(): array
    {
        return array_values(array_map('strval', ApiScope::arrays()));
    }

    /**
     * The read-only scopes ("read only" on the page).
     *
     * @return array<int, string>
     */
    public static function readOnlyScopes(): array
    {
        return [ApiScope::ME, ApiScope::TABLE_READ, ApiScope::VIEW_READ, ApiScope::VALUE_READ, ApiScope::WORKFLOW_READ, ApiScope::NOTIFY_READ];
    }

    /**
     * Make a key. The plain key is returned once, here, and is not kept.
     *
     * @param int $userId the id of the user (custom value of "user")
     * @param array<int, string> $scopes
     * @param array<int, string>|null $tables the table names it may use (null: all the user may)
     * @return array{0: UserApiKey, 1: string}
     * @throws \InvalidArgumentException when a value is not allowed
     */
    public static function create(
        int $userId,
        string $name,
        array $scopes,
        ?array $tables = null,
        ?string $allowedIps = null,
        ?\DateTimeInterface $expiresAt = null,
        ?string $clientCertFingerprint = null,
        ?int $createdUserId = null,
        ?int $loginUserId = null
    ): array {
        // the login the key signs in as: the one that made it (a user of SSO has no password login)
        if (isset($loginUserId)) {
            $login_user = LoginUser::find($loginUserId);
            if (!isset($login_user) || intval($login_user->base_user_id) !== $userId) {
                throw new \InvalidArgumentException('user');
            }
        }

        // the keys a user has at once
        $active = UserApiKey::where('user_id', $userId)->whereNull('revoked_at')->where('expires_at', '>', Carbon::now())->count();
        if ($active >= static::maxActiveKeys()) {
            throw new \InvalidArgumentException('too_many');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 256) {
            throw new \InvalidArgumentException('name');
        }

        $scopes = array_values(array_unique(array_map('strval', $scopes)));
        if (count($scopes) === 0 || count(array_diff($scopes, static::availableScopes())) > 0) {
            throw new \InvalidArgumentException('scopes');
        }

        if (isset($tables)) {
            $names = [];
            foreach ($tables as $table) {
                $custom_table = CustomTable::getEloquent($table);
                if (!isset($custom_table)) {
                    throw new \InvalidArgumentException('tables');
                }
                $names[] = $custom_table->table_name;
            }
            if (count($names) === 0) {
                throw new \InvalidArgumentException('tables');
            }
            $tables = array_values(array_unique($names));
        }

        $ips = static::parseIps($allowedIps);

        $now = Carbon::now();
        $expiresAt = isset($expiresAt) ? Carbon::instance($expiresAt) : $now->copy()->addDays(min(static::DEFAULT_DAYS, static::maxDays()));
        if (!$expiresAt->isFuture() || $expiresAt->greaterThan($now->copy()->addDays(static::maxDays()))) {
            throw new \InvalidArgumentException('expires_at');
        }

        $fingerprint = null;
        if (!is_nullorempty($clientCertFingerprint)) {
            $fingerprint = static::normalizeFingerprint((string)$clientCertFingerprint);
            if (!isset($fingerprint)) {
                throw new \InvalidArgumentException('client_cert_fingerprint');
            }
        }

        $plain = static::PREFIX . bin2hex(random_bytes(24));
        $key = UserApiKey::create([
            'user_id' => $userId,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => static::hash($plain),
            'scopes' => $scopes,
            'tables' => $tables,
            'allowed_ips' => count($ips) ? implode("\n", $ips) : null,
            'client_cert_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'created_user_id' => $createdUserId,
            'login_user_id' => $loginUserId,
        ]);

        return [$key, $plain];
    }

    /**
     * The most days a key may have (EXMENT_API_KEY_MAX_DAYS, never more than a year).
     */
    public static function maxDays(): int
    {
        return max(1, min(static::MAX_DAYS, intval(config('exment.api_key_max_days', static::MAX_DAYS))));
    }

    /**
     * The keys a user may have at once (EXMENT_API_KEY_MAX_PER_USER).
     */
    public static function maxActiveKeys(): int
    {
        return max(1, intval(config('exment.api_key_max_per_user', 10)));
    }

    /**
     * The IP addresses or ranges of a text (one per line or comma separated).
     *
     * @return array<int, string>
     * @throws \InvalidArgumentException when one is not an address or a range
     */
    public static function parseIps(?string $text): array
    {
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$text) ?: [])));
        foreach ($ips as $ip) {
            [$address, $mask] = array_pad(explode('/', $ip, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('allowed_ips');
            }
            $max = str_contains((string)$address, ':') ? 128 : 32;
            if (isset($mask) && (!ctype_digit($mask) || (int)$mask > $max)) {
                throw new \InvalidArgumentException('allowed_ips');
            }
        }
        return $ips;
    }

    /**
     * A sha256 fingerprint as 64 lower-case hex characters ("AB:CD:..." is taken), or null.
     */
    public static function normalizeFingerprint(string $value): ?string
    {
        $hex = strtolower(str_replace([':', ' '], '', trim($value)));
        return preg_match('/\A[0-9a-f]{64}\z/', $hex) === 1 ? $hex : null;
    }

    /**
     * The fingerprint of the client certificate of the request, as the front server checked it:
     * - the header set in ADMIN_API_CLIENT_CERT_HEADER, only when the request came through a
     *   trusted proxy (a client cannot write it itself): a fingerprint, or the certificate
     *   (PEM, URL-encoded as nginx $ssl_client_escaped_cert and the ALB X-Amzn-Mtls-Clientcert)
     * - or SSL_CLIENT_CERT of the web server itself, when SSL_CLIENT_VERIFY is SUCCESS
     */
    public static function clientCertFingerprint(Request $request): ?string
    {
        $header = config('exment.api_client_cert_header');
        if (!is_nullorempty($header) && $request->isFromTrustedProxy()) {
            $value = $request->headers->get((string)$header);
            if (!is_nullorempty($value)) {
                return static::fingerprintOf((string)$value);
            }
        }

        $cert = $request->server('SSL_CLIENT_CERT');
        if ($request->server('SSL_CLIENT_VERIFY') === 'SUCCESS' && is_string($cert) && $cert !== '') {
            return static::fingerprintOf($cert);
        }
        return null;
    }

    protected static function fingerprintOf(string $value): ?string
    {
        $fingerprint = static::normalizeFingerprint($value);
        if (isset($fingerprint)) {
            return $fingerprint;
        }
        $pem = str_contains($value, 'BEGIN CERTIFICATE') ? $value : rawurldecode($value);
        if (!str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }
        $result = @openssl_x509_fingerprint($pem, 'sha256');
        return is_string($result) ? strtolower($result) : null;
    }

    /**
     * The login user of a request made with a key, with the key as its token; null when the key
     * may not be used (why: static::$lastRefusal). Nothing tells the client why.
     */
    public static function authenticate(Request $request, string $plain): ?LoginUser
    {
        static::$lastRefusal = null;
        $refuse = function (string $why, ?UserApiKey $key = null) {
            static::$lastRefusal = $why;
            \Log::info('[Exment] an API key was refused: ' . $why . (isset($key) ? " (key {$key->id})" : ''));
            // on the key, for its pages (the administrators of a SaaS cannot read the log)
            if (isset($key)) {
                UserApiKey::where('id', $key->id)->update(['last_refused_at' => Carbon::now(), 'last_refused_reason' => $why]);
            }
            return null;
        };

        if (!Features::enabled(Features::API_KEYS)) {
            return $refuse('feature');
        }
        if (!static::looksLikeKey($plain) || strlen($plain) > 128) {
            return $refuse('format');
        }

        /** @var UserApiKey|null $key */
        $key = UserApiKey::where('key_hash', static::hash($plain))->first();
        if (!isset($key)) {
            return $refuse('unknown');
        }
        if (isset($key->revoked_at)) {
            return $refuse('revoked', $key);
        }
        if (!$key->expires_at->isFuture()) {
            return $refuse('expired', $key);
        }

        // only the API (not the OAuth pages under the same middleware, as oauth/personal-access-tokens:
        // a key must not make a token that outlives it or has more scopes)
        if (!static::isApiRoute($request)) {
            return $refuse('route', $key);
        }

        $user = getModelName(SystemTableName::USER)::find($key->user_id);
        $login_user = static::loginUserOf($key, $user);
        if (!isset($user) || !isset($login_user)) {
            return $refuse('user', $key);
        }
        if (!Features::allowed(Features::API_KEYS, $login_user)) {
            return $refuse('not_allowed', $key);
        }

        $ips = $key->ipList();
        if (count($ips) > 0 && !IpUtils::checkIp((string)$request->ip(), $ips)) {
            return $refuse('ip', $key);
        }

        if (isset($key->client_cert_fingerprint) || boolval(config('exment.api_key_require_client_cert', false))) {
            $fingerprint = static::clientCertFingerprint($request);
            if (!isset($fingerprint)) {
                return $refuse('cert_missing', $key);
            }
            if (isset($key->client_cert_fingerprint) && !hash_equals($key->client_cert_fingerprint, $fingerprint)) {
                return $refuse('cert_mismatch', $key);
            }
        }

        if (!static::allowsRoute($key, $request)) {
            return $refuse('table', $key);
        }

        UserApiKey::where('id', $key->id)->update([
            'last_used_at' => Carbon::now(),
            'last_used_ip' => substr((string)$request->ip(), 0, 64),
            'use_count' => \DB::raw('use_count + 1'),
        ]);

        $login_user->withAccessToken(new UserApiKeyToken($key));
        return $login_user;
    }

    /**
     * Whether the reason of a refusal means "the key is good, but not for this" (403), not "who
     * are you" (401).
     */
    public static function isForbiddenReason(?string $why): bool
    {
        return in_array($why, ['table', 'route'], true);
    }

    /**
     * Whether the request is to the API ({admin}/api/...).
     */
    public static function isApiRoute(Request $request): bool
    {
        $route = $request->route();
        if (!($route instanceof \Illuminate\Routing\Route)) {
            return false;
        }
        $prefix = trim(url_join(strval(config('admin.route.prefix')), 'api'), '/');
        return str_starts_with(trim($route->uri(), '/') . '/', $prefix . '/');
    }

    /**
     * The login of the key: the one that made it (any provider, as SSO), still of the same user;
     * for a key without it, the password login of the user.
     *
     * @param mixed $user
     */
    protected static function loginUserOf(UserApiKey $key, $user): ?LoginUser
    {
        if (!isset($user)) {
            return null;
        }
        if (isset($key->login_user_id)) {
            /** @var LoginUser|null $login_user */
            $login_user = LoginUser::find($key->login_user_id);
            return isset($login_user) && intval($login_user->base_user_id) === intval($user->id) ? $login_user : null;
        }
        /** @var LoginUser|null $login_user */
        $login_user = $user->login_user ?? null;
        return $login_user;
    }

    /**
     * Whether a key limited to some tables may use the route of the request: the route names
     * one of its tables, or it reads no table (me, avatar, version). What reads other tables
     * than the one of the route is refused for it: the related records (?children, ?parents),
     * the choices and linkage of columns, and the views (their columns may come from other
     * tables). A file is checked by the table it really belongs to, not by the URL.
     */
    public static function allowsRoute(UserApiKey $key, Request $request): bool
    {
        if (!isset($key->tables)) {
            return true;
        }
        $route = $request->route();
        if (!($route instanceof \Illuminate\Routing\Route)) {
            return false;
        }
        $action = $route->getActionName();
        $is = function (array $actions) use ($action): bool {
            foreach ($actions as $one) {
                if (str_ends_with($action, '\\' . $one)) {
                    return true;
                }
            }
            return false;
        };

        // the files: by the table of the record the file belongs to
        if ($is(static::FILE_ROUTES)) {
            $uuid = $route->parameter('uuid');
            $tableKey = $route->parameter('tableKey');
            $file = is_string($uuid) ? File::getData(isset($tableKey) ? [url_join($tableKey, $uuid), $uuid] : $uuid) : null;
            $parent = isset($file) ? CustomTable::getEloquent($file->parent_type) : null;
            return isset($parent) && in_array($parent->table_name, (array)$key->tables, true);
        }

        $tableKey = $route->parameter('tableKey');
        if (!isset($tableKey)) {
            return $is(array_merge(static::ROUTES_WITHOUT_TABLE, static::ROUTES_CHECKING_TABLES));
        }
        if ($is(static::ROUTES_READING_OTHER_TABLES) || boolval($request->query('children')) || boolval($request->query('parents'))) {
            return false;
        }
        $custom_table = CustomTable::getEloquent($tableKey);
        return isset($custom_table) && in_array($custom_table->table_name, (array)$key->tables, true);
    }

    /**
     * Whether the login user of the request may use the table with its token: always with an
     * OAuth token; with an API key, when the key has no tables or has this one.
     */
    public static function allowsTable(LoginUser $user, string $tableName): bool
    {
        $token = $user->token();
        if (!($token instanceof UserApiKeyToken)) {
            return true;
        }
        $tables = $token->key()->tables;
        return !isset($tables) || in_array($tableName, (array)$tables, true);
    }

    /**
     * Stop a key at once.
     */
    public static function revoke(UserApiKey $key, ?int $byUserId = null): void
    {
        if (isset($key->revoked_at)) {
            return;
        }
        $key->revoked_at = Carbon::now();
        $key->revoked_user_id = $byUserId;
        $key->save();
    }

    /**
     * Stop all the keys of a user at once. Returns how many were stopped.
     */
    public static function revokeAllOfUser(int $userId, ?int $byUserId = null): int
    {
        return UserApiKey::where('user_id', $userId)->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now(), 'revoked_user_id' => $byUserId]);
    }
}

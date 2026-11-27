<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\ApiRateRaise;
use Exceedone\Exment\Model\UserApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Temporary raises of the API rate limit (ISS00442). A system administrator lets a user or
 * an API client make more requests per minute (than config exment.api_max_rate_limit) until
 * a time, for an import or a move of data, without a change on the server.
 *
 * - The "api" rate limiter (RouteServiceProvider) asks perMinute(). The raises in use are
 *   kept in the cache: the database is read only when they change or the cache runs out.
 * - A raise past its time is not used; it stays in the list.
 * - A SaaS sets a ceiling (EXMENT_API_MAX_RATE_LIMIT_CEILING): no raise may be over it.
 * - The feature api_rate_raise (off until it is turned on), the system administrators only.
 */
class ApiRateRaises
{
    public const FEATURE = 'api_rate_raise';

    /** How long the raises in use stay in the cache (a change clears it at once). */
    public const CACHE_SECONDS = 300;

    public const CACHE_KEY = 'exment_api_rate_raises:';

    /**
     * The raises in use this request: [type => [id => [per_minute, expires_at (timestamp)]]]
     *
     * @var array<string, array<string, array{0: int, 1: int}>>|null
     */
    protected static ?array $memo = null;

    public static function used(): bool
    {
        return Features::enabled(static::FEATURE);
    }

    /** The rate limit of the site (requests per minute). */
    public static function base(): int
    {
        return max(1, intval(config('exment.api_max_rate_limit', 60)));
    }

    /** The most requests per minute a raise may give; null: no ceiling (on premises). */
    public static function ceiling(): ?int
    {
        $value = config('exment.api_max_rate_limit_ceiling');
        if (is_null($value) || $value === '' || intval($value) <= 0) {
            return null;
        }
        return intval($value);
    }

    /** The longest a raise may last (hours). */
    public static function maxHours(): int
    {
        return max(1, intval(config('exment.api_rate_raise_max_hours', 24)));
    }

    /**
     * The requests per minute of a request: the rate limit of the site, or more when a
     * raise in use is for its user or its API client.
     *
     * @param int|string|null $userId the user (base_user_id) of the request
     * @param int|string|null $clientId the API client of the request
     */
    public static function perMinute(int $base, $userId = null, $clientId = null): int
    {
        if (is_null($userId) && is_null($clientId)) {
            return $base;
        }
        if (!static::used()) {
            return $base;
        }
        $raised = max(
            static::raisedFor(ApiRateRaise::TARGET_USER, $userId),
            static::raisedFor(ApiRateRaise::TARGET_CLIENT, $clientId)
        );
        $ceiling = static::ceiling();
        if (isset($ceiling)) {
            // a raise made before the ceiling was lowered
            $raised = min($raised, $ceiling);
        }
        return max($base, $raised);
    }

    /**
     * The user of a user API key (ISS00437): read only while a raise of a user is in use.
     */
    public static function userOfApiKey(string $plain): ?int
    {
        if (!static::used() || count(static::active()[ApiRateRaise::TARGET_USER] ?? []) === 0) {
            return null;
        }
        $userId = UserApiKey::where('key_hash', UserApiKeys::hash($plain))->whereNull('revoked_at')->value('user_id');
        return isset($userId) ? intval($userId) : null;
    }

    /**
     * @param int|string|null $id
     */
    protected static function raisedFor(string $type, $id): int
    {
        if (is_null($id) || $id === '') {
            return 0;
        }
        $found = static::active()[$type][strval($id)] ?? null;
        if (!isset($found) || $found[1] <= Carbon::now()->getTimestamp()) {
            return 0;
        }
        return $found[0];
    }

    /**
     * The raises in use, from the cache.
     *
     * @return array<string, array<string, array{0: int, 1: int}>>
     */
    public static function active(): array
    {
        if (isset(static::$memo)) {
            return static::$memo;
        }
        return static::$memo = Cache::remember(static::cacheKey(), static::CACHE_SECONDS, function () {
            $active = [];
            try {
                $raises = ApiRateRaise::query()->where('expires_at', '>', Carbon::now())->get();
            } catch (\Illuminate\Database\QueryException $e) {
                // (the table not made yet: before the update ran)
                return [];
            }
            foreach ($raises as $raise) {
                $current = $active[$raise->target_type][$raise->target_id] ?? null;
                // two raises of one target: the higher
                if (!isset($current) || $current[0] < $raise->per_minute) {
                    $active[$raise->target_type][$raise->target_id] = [$raise->per_minute, $raise->expires_at->getTimestamp()];
                }
            }
            return $active;
        });
    }

    /**
     * Check a new raise: null when it may be made, or why not.
     *
     * @param array<string, mixed> $input target_type, target_id, per_minute, hours
     */
    public static function validate(array $input): ?string
    {
        $type = strval($input['target_type'] ?? '');
        $id = strval($input['target_id'] ?? '');
        if (!in_array($type, [ApiRateRaise::TARGET_USER, ApiRateRaise::TARGET_CLIENT], true) || $id === '') {
            return strval(exmtrans('api_rate_raise.errors.target'));
        }
        if (!static::targetExists($type, $id)) {
            return strval(exmtrans('api_rate_raise.errors.target'));
        }
        $perMinute = $input['per_minute'] ?? null;
        if (!is_numeric($perMinute) || intval($perMinute) != $perMinute || intval($perMinute) <= static::base()) {
            return strval(exmtrans('api_rate_raise.errors.per_minute', ['base' => static::base()]));
        }
        $ceiling = static::ceiling();
        if (isset($ceiling) && intval($perMinute) > $ceiling) {
            return strval(exmtrans('api_rate_raise.errors.ceiling', ['ceiling' => $ceiling]));
        }
        $hours = $input['hours'] ?? null;
        if (!is_numeric($hours) || intval($hours) != $hours || intval($hours) < 1 || intval($hours) > static::maxHours()) {
            return strval(exmtrans('api_rate_raise.errors.hours', ['max' => static::maxHours()]));
        }
        return null;
    }

    public static function targetExists(string $type, string $id): bool
    {
        if ($type === ApiRateRaise::TARGET_USER) {
            return ctype_digit($id) && getModelName(\Exceedone\Exment\Enums\SystemTableName::USER)::query()->whereKey(intval($id))->exists();
        }
        return ApiClient::query()->whereKey($id)->exists();
    }

    /**
     * Make a raise (checked with validate() first).
     *
     * @param array<string, mixed> $input target_type, target_id, per_minute, hours, reason
     */
    public static function add(array $input, ?int $byUserId): ApiRateRaise
    {
        $raise = ApiRateRaise::create([
            'target_type' => strval($input['target_type']),
            'target_id' => strval($input['target_id']),
            'per_minute' => intval($input['per_minute']),
            'expires_at' => Carbon::now()->addHours(intval($input['hours'])),
            'reason' => mb_substr(trim(strval($input['reason'] ?? '')), 0, 1000) ?: null,
            'created_user_id' => $byUserId,
        ]);
        static::forget();
        static::log('added', $raise, $byUserId);
        return $raise;
    }

    public static function delete(ApiRateRaise $raise, ?int $byUserId): void
    {
        $raise->delete();
        static::forget();
        static::log('deleted', $raise, $byUserId);
    }

    /** The raises in use changed: read them again. */
    public static function forget(): void
    {
        Cache::forget(static::cacheKey());
        static::$memo = null;
    }

    /** (the tests, and a new request in a long process) */
    public static function reset(): void
    {
        static::$memo = null;
    }

    protected static function cacheKey(): string
    {
        return static::CACHE_KEY . UserApiKeys::siteHash();
    }

    protected static function log(string $action, ApiRateRaise $raise, ?int $byUserId): void
    {
        Log::info('[Exment] API rate limit raise ' . $action, [
            'id' => $raise->id,
            'target_type' => $raise->target_type,
            'target_id' => $raise->target_id,
            'per_minute' => $raise->per_minute,
            'expires_at' => $raise->expires_at->toIso8601String(),
            'reason' => $raise->reason,
            'user_id' => $byUserId,
        ]);
    }
}

<?php

namespace Exceedone\Exment\Services\Encryption;

use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProvider;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviderException;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviders;
use Illuminate\Encryption\Encrypter;

/**
 * The keys of the encrypted columns (ISS00148): not APP_KEY.
 *
 * A backup keeps APP_KEY and the EXMENT_* settings of .env (Backup::getMatchedEnv), so a value
 * encrypted with APP_KEY could be read from a backup alone. By default these keys are in .env as
 * DATA_ENCRYPTION_KEYS, a name a backup does not keep, and never in the database:
 *
 *   DATA_ENCRYPTION_KEYS="k2:base64...,k1:base64..."
 *
 * "id:key" pairs, the key 32 bytes in base64. The first one encrypts; the others are kept to
 * read the values encrypted before a rotation (exment:data-key rotate), until no value uses them.
 *
 * They can come from a service for keys instead (ISS02929, EXMENT_DATA_KEY_PROVIDER: aws_kms,
 * aws_secrets_manager, vault; KeyProviders). The keys read are kept in memory only: for the
 * request, and in APCu when it is there (EXMENT_DATA_KEY_CACHE_SECONDS, encrypted with APP_KEY,
 * so the shared memory alone does not give them). When the service cannot be reached, there are
 * no keys and unavailable() says why: nothing falls back to another key (the encrypted columns
 * are "cannot be read now", and a value of them cannot be saved).
 */
class DataKeys
{
    public const CIPHER = 'aes-256-gcm';

    /** The prefix of the APCu entry. */
    public const CACHE_PREFIX = 'exment_data_keys:';

    /** @var array<string, string>|null id => raw key, the first the current one */
    protected static $keys;

    /** @var array<string, Encrypter> */
    protected static $encrypters = [];

    /** @var string|null why the keys could not be read from the provider (no secret in it) */
    protected static $unavailable;

    /** @var KeyProvider|null set by a test */
    public static $provider;

    /**
     * The "id:value" pairs of a setting ("k2:...,k1:..."), in their order; a pair with a bad id
     * or a second one of an id is left out. The value is what is after the first ":".
     *
     * @return array<string, string>
     */
    public static function pairs(string $text): array
    {
        $pairs = [];
        foreach (explode(',', $text) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }
            [$id, $value] = explode(':', $pair, 2);
            $id = trim($id);
            $value = trim($value);
            if (!static::validId($id) || $value === '' || isset($pairs[$id])) {
                continue;
            }
            $pairs[$id] = $value;
        }
        return $pairs;
    }

    /**
     * @return array<string, string> id => raw key, the current one first
     */
    public static function all(): array
    {
        if (isset(static::$keys)) {
            return static::$keys;
        }
        static::$unavailable = null;
        $cacheKey = static::CACHE_PREFIX . KeyProviders::fingerprint();
        $cached = static::fromCache($cacheKey);
        if (isset($cached)) {
            return static::$keys = $cached;
        }
        try {
            $provider = static::$provider ?? KeyProviders::make();
            $keys = [];
            foreach ($provider->keys() as $id => $raw) {
                if (static::validId(strval($id)) && strlen($raw) === 32) {
                    $keys[strval($id)] = $raw;
                }
            }
        } catch (KeyProviderException $e) {
            // once a request: the next request asks again
            static::$unavailable = $e->getMessage();
            \Log::warning('[Exment] the keys of the encrypted columns could not be read: ' . $e->getMessage());
            return static::$keys = [];
        } catch (\Throwable $e) {
            static::$unavailable = class_basename($e);
            \Log::warning('[Exment] the keys of the encrypted columns could not be read: ' . class_basename($e));
            return static::$keys = [];
        }
        static::toCache($cacheKey, $keys);
        return static::$keys = $keys;
    }

    /**
     * @return array<string, string>|null
     */
    protected static function fromCache(string $key): ?array
    {
        if (KeyProviders::isEnv() || !static::cacheSeconds() || !function_exists('apcu_fetch')) {
            return null;
        }
        $ok = false;
        $stored = apcu_fetch($key, $ok);
        if (!$ok || !is_string($stored)) {
            return null;
        }
        try {
            $json = json_decode(decrypt($stored, false), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($json)) {
            return null;
        }
        $keys = [];
        foreach ($json as $id => $value) {
            $raw = base64_decode(strval($value), true);
            if ($raw !== false && strlen($raw) === 32) {
                $keys[strval($id)] = $raw;
            }
        }
        return count($keys) > 0 ? $keys : null;
    }

    /**
     * @param array<string, string> $keys
     */
    protected static function toCache(string $key, array $keys): void
    {
        if (KeyProviders::isEnv() || count($keys) === 0 || !static::cacheSeconds() || !function_exists('apcu_store')) {
            return;
        }
        try {
            apcu_store($key, encrypt(json_encode(array_map('base64_encode', $keys)), false), static::cacheSeconds());
        } catch (\Throwable $e) {
            // the cache is only to call the provider less
        }
    }

    protected static function cacheSeconds(): int
    {
        return max(0, intval(config('exment.data_key_cache_seconds', 300)));
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9]{1,16}$/', $id) === 1;
    }

    public static function hasKey(): bool
    {
        return count(static::all()) > 0;
    }

    /**
     * Whether the site uses keys of its own for the encrypted columns: a provider other than
     * env is set, or DATA_ENCRYPTION_KEYS is. True while the provider cannot be reached, so what
     * would fall back to APP_KEY without keys (SyncSecrets) does not then.
     */
    public static function configured(): bool
    {
        if (!KeyProviders::isEnv()) {
            return true;
        }
        return trim(strval(config('exment.data_encryption_keys', ''))) !== '';
    }

    /**
     * Why the keys cannot be read now (the provider is not reached...), null: they can, or none
     * is set.
     */
    public static function unavailable(): ?string
    {
        static::all();
        return static::$unavailable;
    }

    /**
     * The id of the key that encrypts, null: no key.
     */
    public static function currentId(): ?string
    {
        $ids = array_keys(static::all());
        return $ids[0] ?? null;
    }

    public static function has(string $id): bool
    {
        return array_key_exists($id, static::all());
    }

    public static function encrypter(string $id): ?Encrypter
    {
        $raw = static::all()[$id] ?? null;
        if (!isset($raw)) {
            return null;
        }
        return static::$encrypters[$id] ??= new Encrypter($raw, static::CIPHER);
    }

    /**
     * The next free id ("k<n>") among the keys of the setting.
     */
    public static function nextId(): string
    {
        $n = 1;
        while (static::has('k' . $n)) {
            $n++;
        }
        return 'k' . $n;
    }

    /**
     * A new key, as it is written in DATA_ENCRYPTION_KEYS.
     *
     * @return array{id: string, pair: string}
     */
    public static function generate(?string $id = null): array
    {
        $id = $id ?? static::nextId();
        return ['id' => $id, 'pair' => $id . ':' . base64_encode(Encrypter::generateKey(static::CIPHER))];
    }

    /**
     * Forget the keys read (after the setting changed: the tests, the commands), and the APCu
     * entry of this setting.
     */
    public static function reset(): void
    {
        static::$keys = null;
        static::$encrypters = [];
        static::$unavailable = null;
        if (function_exists('apcu_delete')) {
            try {
                apcu_delete(static::CACHE_PREFIX . KeyProviders::fingerprint());
            } catch (\Throwable $e) {
                // nothing kept
            }
        }
    }
}

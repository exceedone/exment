<?php

namespace Exceedone\Exment\Services\Encryption;

use Illuminate\Encryption\Encrypter;

/**
 * The keys of the encrypted columns (ISS00148): not APP_KEY.
 *
 * A backup keeps APP_KEY and the EXMENT_* settings of .env (Backup::getMatchedEnv), so a value
 * encrypted with APP_KEY could be read from a backup alone. These keys are in .env as
 * DATA_ENCRYPTION_KEYS, a name a backup does not keep, and never in the database:
 *
 *   DATA_ENCRYPTION_KEYS="k2:base64...,k1:base64..."
 *
 * "id:key" pairs, the key 32 bytes in base64. The first one encrypts; the others are kept to
 * read the values encrypted before a rotation (exment:data-key rotate), until no value uses them.
 */
class DataKeys
{
    public const CIPHER = 'aes-256-gcm';

    /** @var array<string, string>|null id => raw key, the first the current one */
    protected static $keys;

    /** @var array<string, Encrypter> */
    protected static $encrypters = [];

    /**
     * @return array<string, string> id => raw key, the current one first
     */
    public static function all(): array
    {
        if (isset(static::$keys)) {
            return static::$keys;
        }
        $keys = [];
        foreach (explode(',', strval(config('exment.data_encryption_keys', ''))) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }
            [$id, $key] = explode(':', $pair, 2);
            $id = trim($id);
            $raw = base64_decode(trim($key), true);
            if (!static::validId($id) || $raw === false || strlen($raw) !== 32 || isset($keys[$id])) {
                continue;
            }
            $keys[$id] = $raw;
        }
        return static::$keys = $keys;
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
     * A new key, as it is written in DATA_ENCRYPTION_KEYS.
     *
     * @return array{id: string, pair: string}
     */
    public static function generate(?string $id = null): array
    {
        if (!isset($id)) {
            // the next free "k<n>"
            $n = 1;
            while (static::has('k' . $n)) {
                $n++;
            }
            $id = 'k' . $n;
        }
        return ['id' => $id, 'pair' => $id . ':' . base64_encode(Encrypter::generateKey(static::CIPHER))];
    }

    /**
     * Forget the keys read (after the setting changed: the tests, the commands).
     */
    public static function reset(): void
    {
        static::$keys = null;
        static::$encrypters = [];
    }
}

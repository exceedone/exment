<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedValue;

/**
 * The secrets of the connections of the data sync (ISS00113): with the keys of the encrypted
 * columns (DATA_ENCRYPTION_KEYS) when there are any, which a backup does not keep; else with
 * APP_KEY, which a backup keeps, and the page says so (snoro 2026-11-26).
 *
 * "There are keys" is whether they are set (DataKeys::configured), not whether they were read: a
 * service of the keys that is not reached now (ISS02929) does not make a secret go to APP_KEY.
 */
class SyncSecrets
{
    /** A secret encrypted with APP_KEY begins with this. */
    public const APP_PREFIX = 'app:';

    public static function usesAppKey(): bool
    {
        return !DataKeys::configured();
    }

    public static function encrypt(string $plain): string
    {
        if (DataKeys::configured()) {
            return EncryptedValue::encrypt($plain);
        }
        return static::APP_PREFIX . encrypt($plain);
    }

    public static function decrypt(?string $stored): ?string
    {
        if (is_nullorempty($stored)) {
            return null;
        }
        $stored = strval($stored);
        if (EncryptedValue::isEncrypted($stored)) {
            return EncryptedValue::decrypt($stored);
        }
        if (str_starts_with($stored, static::APP_PREFIX)) {
            try {
                return strval(decrypt(substr($stored, strlen(static::APP_PREFIX))));
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }
}

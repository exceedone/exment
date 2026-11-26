<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Encryption\Secrets;

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
        return Secrets::usesAppKey();
    }

    public static function encrypt(string $plain): string
    {
        return Secrets::encrypt($plain);
    }

    public static function decrypt(?string $stored): ?string
    {
        if (is_nullorempty($stored)) {
            return null;
        }
        $stored = strval($stored);
        // a secret of a connection is always one of the two (never the bare payload)
        if (!EncryptedValue::isEncrypted($stored) && !str_starts_with($stored, static::APP_PREFIX)) {
            return null;
        }
        return Secrets::decrypt($stored);
    }
}

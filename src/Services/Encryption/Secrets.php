<?php

namespace Exceedone\Exment\Services\Encryption;

/**
 * A secret of a setting (a token, a client secret, a private key...), encrypted with the keys of
 * the encrypted columns when the site has them (DataKeys::configured: DATA_ENCRYPTION_KEYS, or a
 * service of keys, ISS02929), which a backup does not keep; else with APP_KEY, which it keeps.
 *
 * What is kept tells which: "enc:v1:..." (the keys of the columns), "app:..." (APP_KEY), or the
 * plain payload of Laravel's encrypt() of the settings saved before (APP_KEY too). A secret is
 * read whichever it is, and saved again the way of now on its next save.
 *
 * "Has keys" is whether they are set, not whether they were read: while the service of the keys
 * is not reached, a secret is not saved with APP_KEY instead (EncryptedValue::encrypt throws).
 */
class Secrets
{
    /** A secret encrypted with APP_KEY begins with this. */
    public const APP_PREFIX = 'app:';

    public static function usesAppKey(): bool
    {
        return !DataKeys::configured();
    }

    /**
     * @param bool $appPrefix false: with APP_KEY, the bare payload of encrypt() (as the settings kept it)
     * @throws \RuntimeException the keys are set but cannot be read now
     */
    public static function encrypt(string $plain, bool $appPrefix = true): string
    {
        if (DataKeys::configured()) {
            return EncryptedValue::encrypt($plain);
        }
        return ($appPrefix ? static::APP_PREFIX : '') . encrypt($plain);
    }

    /**
     * The plain secret, null when it cannot be read (no key, the service not reached, changed).
     */
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
            $stored = substr($stored, strlen(static::APP_PREFIX));
        }
        try {
            return strval(decrypt($stored));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether a kept secret is not the way of now (APP_KEY while the site has keys): saved again,
     * it would be.
     */
    public static function needsResave(?string $stored): bool
    {
        if (is_nullorempty($stored)) {
            return false;
        }
        $stored = strval($stored);
        return DataKeys::configured() !== EncryptedValue::isEncrypted($stored) || EncryptedValue::needsRotation($stored);
    }

    /**
     * The settings kept with "data_key" (Define::SYSTEM_SETTING_NAME_VALUE) saved again the way of
     * now: with the current key of the columns when the site has keys, else APP_KEY. A secret that
     * cannot be read is left as it is.
     *
     * @return array{saved: int, unreadable: int}
     */
    public static function resaveSettings(): array
    {
        $result = ['saved' => 0, 'unreadable' => 0];
        foreach (\Exceedone\Exment\Services\CoreExtensions::systemSettings() as $name => $setting) {
            if (!boolval(array_get($setting, 'data_key')) || array_get($setting, 'type') !== 'password') {
                continue;
            }
            $stored = \Exceedone\Exment\Model\System::query()->where('system_name', $name)->value('system_value');
            if (!static::needsResave(is_null($stored) ? null : strval($stored))) {
                continue;
            }
            $plain = static::decrypt(strval($stored));
            if (!isset($plain)) {
                $result['unreadable']++;
                continue;
            }
            \Exceedone\Exment\Model\System::{$name}($plain);
            $result['saved']++;
        }
        \Exceedone\Exment\Model\System::clearCache();
        return $result;
    }
}

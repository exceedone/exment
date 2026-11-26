<?php

namespace Exceedone\Exment\Services\Encryption;

/**
 * One value of an encrypted column (ISS00148), as the database keeps it:
 *
 *   enc:v1:<key id>:<the payload of Laravel's Encrypter, aes-256-gcm>
 *
 * The prefix tells an encrypted value from a plain one, so the values of a column being
 * converted (either way) are read right whichever they are.
 */
class EncryptedValue
{
    public const PREFIX = 'enc:v1:';

    /** What the masked value starts with. */
    public const MASK = '****';

    /**
     * @param mixed $value
     */
    public static function isEncrypted($value): bool
    {
        return is_string($value) && str_starts_with($value, static::PREFIX);
    }

    /**
     * The id of the key a value was encrypted with, null: not encrypted.
     *
     * @param mixed $value
     */
    public static function keyId($value): ?string
    {
        if (!static::isEncrypted($value)) {
            return null;
        }
        $rest = substr($value, strlen(static::PREFIX));
        $id = strstr($rest, ':', true);
        return $id === false ? null : $id;
    }

    /**
     * Encrypt with the current key.
     *
     * @throws \RuntimeException when there is no key (DATA_ENCRYPTION_KEYS)
     */
    public static function encrypt(string $plain): string
    {
        $id = DataKeys::currentId();
        $encrypter = isset($id) ? DataKeys::encrypter($id) : null;
        if (!isset($id, $encrypter)) {
            // the service of the keys not reached (ISS02929): never saved as plain text
            $unavailable = DataKeys::unavailable();
            if (isset($unavailable)) {
                throw new \RuntimeException(strval(exmtrans('encrypted.unavailable_save')));
            }
            throw new \RuntimeException('No key to encrypt the column with: set DATA_ENCRYPTION_KEYS (php artisan exment:data-key generate).');
        }
        return static::PREFIX . $id . ':' . $encrypter->encryptString($plain);
    }

    /**
     * The plain value, null when it cannot be read (its key is not here, or it was changed).
     */
    public static function decrypt(string $value): ?string
    {
        $id = static::keyId($value);
        $encrypter = isset($id) ? DataKeys::encrypter($id) : null;
        if (!isset($encrypter)) {
            return null;
        }
        try {
            return $encrypter->decryptString(substr($value, strlen(static::PREFIX . $id . ':')));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether a value is encrypted with a key that is not the current one (to encrypt again).
     *
     * @param mixed $value
     */
    public static function needsRotation($value): bool
    {
        $id = static::keyId($value);
        return isset($id) && $id !== DataKeys::currentId();
    }

    /**
     * "****1234": the last characters of the value, the rest hidden. A value not longer than
     * twice the characters shown is hidden whole, so a short one does not show itself.
     */
    public static function mask(?string $plain, int $visible = 4): string
    {
        if (!isset($plain) || $plain === '') {
            return '';
        }
        $visible = max(0, min(4, $visible));
        $length = mb_strlen($plain);
        if ($visible === 0 || $length <= $visible * 2) {
            return static::MASK;
        }
        return static::MASK . mb_substr($plain, $length - $visible);
    }
}

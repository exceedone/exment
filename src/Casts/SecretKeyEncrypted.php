<?php

namespace Exceedone\Exment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Env;

class SecretKeyEncrypted implements CastsAttributes
{
    /**
     * Encrypt the given value using APP_SECRET_KEY.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }

        $secret = Env::get('APP_SECRET_KEY');
        if (!$secret) {
            // If no secret key is configured, store raw value as a fallback
            return [$key => $value];
        }

        $cipher = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);
        $keyBytes = hash('sha256', $secret, true);

        $ciphertext = openssl_encrypt((string)$value, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return [$key => $value];
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $keyBytes, true);
        $payload = base64_encode($iv . $mac . $ciphertext);

        return [$key => $payload];
    }

    /**
     * Decrypt the given value using APP_SECRET_KEY.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $secret = Env::get('APP_SECRET_KEY');
        if (!$secret) {
            return $value; // No secret configured; return as-is
        }

        $cipher = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipher);
        $decoded = base64_decode((string)$value, true);
        if ($decoded === false || strlen($decoded) <= ($ivLength + 32)) {
            return $value; // Not a valid payload
        }

        $keyBytes = hash('sha256', $secret, true);
        $iv = substr($decoded, 0, $ivLength);
        $mac = substr($decoded, $ivLength, 32);
        $ciphertext = substr($decoded, $ivLength + 32);

        $calcMac = hash_hmac('sha256', $iv . $ciphertext, $keyBytes, true);
        if (!hash_equals($mac, $calcMac)) {
            return $value; // Integrity check failed
        }

        $plaintext = openssl_decrypt($ciphertext, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? $value : $plaintext;
    }
}

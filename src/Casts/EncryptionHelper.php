<?php

namespace Exceedone\Exment\Casts;

use Illuminate\Support\Env;

class EncryptionHelper
{
    public static function encrypt(string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $secret = Env::get('APP_SECRET_KEY');
        if (!$secret) {
            return $value;
        }

        $cipher = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = random_bytes($ivLength);
        $keyBytes = hash('sha256', $secret, true);

        $ciphertext = openssl_encrypt($value, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return $value;
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $keyBytes, true);
        return base64_encode($iv . $mac . $ciphertext);
    }

    public static function decrypt(string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $secret = Env::get('APP_SECRET_KEY');
        if (!$secret) {
            return $value;
        }

        $cipher = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipher);
        $decoded = base64_decode($value, true);

        if ($decoded === false || strlen($decoded) <= ($ivLength + 32)) {
            return $value;
        }

        $keyBytes = hash('sha256', $secret, true);
        $iv = substr($decoded, 0, $ivLength);
        $mac = substr($decoded, $ivLength, 32);
        $ciphertext = substr($decoded, $ivLength + 32);

        $calcMac = hash_hmac('sha256', $iv . $ciphertext, $keyBytes, true);
        if (!hash_equals($mac, $calcMac)) {
            return $value;
        }

        $plaintext = openssl_decrypt($ciphertext, $cipher, $keyBytes, OPENSSL_RAW_DATA, $iv);
        return $plaintext === false ? $value : $plaintext;
    }
}

<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

use Exceedone\Exment\Services\Encryption\DataKeys;
use Illuminate\Encryption\Encrypter;

/**
 * The keys in .env (DATA_ENCRYPTION_KEYS="k2:base64...,k1:base64..."), as before ISS02929.
 */
class EnvKeyProvider implements KeyProvider
{
    public const NAME = 'env';

    public function name(): string
    {
        return static::NAME;
    }

    public function keys(): array
    {
        $keys = [];
        foreach (DataKeys::pairs(strval(config('exment.data_encryption_keys', ''))) as $id => $value) {
            $raw = base64_decode($value, true);
            if ($raw !== false && strlen($raw) === 32) {
                $keys[$id] = $raw;
            }
        }
        return $keys;
    }

    public function generate(string $id): array
    {
        $current = strval(config('exment.data_encryption_keys', ''));
        $pair = $id . ':' . base64_encode(Encrypter::generateKey(DataKeys::CIPHER));
        return [
            'id' => $id,
            'pair' => $pair,
            'setting' => 'DATA_ENCRYPTION_KEYS="' . $pair . ($current === '' ? '' : ',' . $current) . '"',
        ];
    }
}

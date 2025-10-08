<?php

namespace Exceedone\Exment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class SecretKeyEncrypted implements CastsAttributes
{
    public function set($model, string $key, $value, array $attributes)
    {
        return [$key => EncryptionHelper::encrypt($value)];
    }

    public function get($model, string $key, $value, array $attributes)
    {
        return EncryptionHelper::decrypt($value);
    }
}

<?php

namespace Exceedone\Exment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class EnvironmentSettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $data = is_array($value) ? $value : json_decode($value, true) ?? [];

        if (array_key_exists('db_password', $data)) {
            $data['db_password'] = EncryptionHelper::decrypt($data['db_password']);
        }

        return $data;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }

        $data = is_array($value) ? $value : json_decode($value, true) ?? [];

        if (array_key_exists('db_password', $data)) {
            $data['db_password'] = EncryptionHelper::encrypt($data['db_password']);
        }

        return [$key => json_encode($data, JSON_UNESCAPED_UNICODE)];
    }
}

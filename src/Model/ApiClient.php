<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ApiClientType;
use Exceedone\Exment\Enums\Permission;
use Laravel\Passport\Client;

/**
 * Extend Laravel\Passport\Client
 * For view Api Setting
 *
 * @property mixed $name
 * @property mixed $personal_access_client
 * @property mixed $password_client
 * @property mixed $api_key_client
 * @property mixed $secret
 * @property mixed $client_api_key
 * @property mixed $redirect
 */
class ApiClient extends Client
{
    protected $appends = ['client_type', 'client_type_text', 'api_key_string'];

    /**
     * The attributes excluded from the model's JSON form.
     * remove secret for display
     *
     * @var array
     */
    protected $hidden = [
        //'secret',
    ];

    protected $keyType = 'string';

    public function client_api_key()
    {
        return $this->hasOne(ApiKey::class, 'client_id');
    }

    public function getApiKeyStringAttribute()
    {
        return $this->client_api_key->key ?? null;
    }

    public function getClientTypeAttribute()
    {
        if (boolval($this->api_key_client)) {
            return ApiClientType::API_KEY;
        }
        if (boolval($this->password_client)) {
            return ApiClientType::PASSWORD_GRANT;
        }
        /** @phpstan-ignore-next-line Negated boolean expression is always true. */
        if (!boolval($this->personal_access_client) && !boolval($this->password_client)) {
            return ApiClientType::CLIENT_CREDENTIALS;
        }
    }

    public function getClientTypeTextAttribute()
    {
        $client_type = $this->client_type;
        switch ($client_type) {
            case ApiClientType::API_KEY:
                return exmtrans('api.client_type_options.api_key');
            case ApiClientType::PASSWORD_GRANT:
                return exmtrans('api.client_type_options.password_grant');
            case ApiClientType::CLIENT_CREDENTIALS:
                return exmtrans('api.client_type_options.client_credentials');
        }
    }

    public static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            if ($model->client_api_key) {
                $model->client_api_key->delete();
            }
        });

        static::addGlobalScope('only_self', function ($builder) {
            $user = \Exment::user();
            if (!isset($user)) {
                return;
            }
            if ($user->hasPermission(Permission::API_ALL)) {
                return;
            }

            $builder->where('user_id', $user->getUserId());
        });
    }
}

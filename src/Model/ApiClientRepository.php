<?php

namespace Exceedone\Exment\Model;

use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Illuminate\Support\Str;

/**
 * Extend Laravel\Passport\ClientRepository
 * For view Api Setting
 */
class ApiClientRepository extends ClientRepository
{
    /**
     * Store a new client for api key.
     *
     * @param int $userId
     * @param string $name
     * @param bool|string $redirect
     * @return \Laravel\Passport\Client
     */
    public function createApiKey($userId, $name, $redirect)
    {
        $client = Passport::client()->forceFill([
            'user_id' => $userId,
            'name' => $name,
            'secret' => Str::random(40),
            'redirect' => $redirect,
            'personal_access_client' => false,
            'password_client' => false,
            'api_key_client' => true,
            'revoked' => false,
        ]);
        $client->save();

        // Save API key
        $apikey = new ApiKey([
            'client_id' => $client->id,
            'key' => 'key_' . Str::random(30),
        ]);
        $apikey->save();

        return $client;
    }
}

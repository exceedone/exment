<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ApiClientType;
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
     * @param  int  $userId
     * @param  string  $name
     * @param  string  $redirect
     * @param  bool  $personalAccess
     * @param  bool  $password
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
            'user_id' => $userId,
            'key' => 'key_' . Str::random(30),
        ]);
        $apikey->save();
        
        return $client;
    }

}

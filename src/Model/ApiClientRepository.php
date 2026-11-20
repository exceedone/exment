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
     * Store a new client for the client credentials / authorization code type
     * (Exment's "client_credentials" client type).
     *
     * Passport 13 made ClientRepository::create() protected and changed its signature,
     * so Exment creates the row itself, with the same columns as before (user_id, redirect,
     * personal_access_client, password_client). Passport 13 still reads these legacy columns.
     * The secret is hashed on save; the plain value is on $client->plainSecret.
     *
     * @param int|string|null $userId
     * @param string $name
     * @param string $redirect
     * @param string|null $secret plain secret; random when null
     * @return \Laravel\Passport\Client
     */
    public function createClientCredentials($userId, $name, $redirect, ?string $secret = null)
    {
        return $this->createExmentClient($userId, $name, $redirect, false, $secret);
    }

    /**
     * Store a new password grant client.
     *
     * @param int|string|null $userId
     * @param string $name
     * @param string $redirect
     * @param string|null $secret plain secret; random when null
     * @return \Laravel\Passport\Client
     */
    public function createPasswordClient($userId, $name, $redirect = 'http://localhost', ?string $secret = null)
    {
        return $this->createExmentClient($userId, $name, $redirect, true, $secret);
    }

    /**
     * @param int|string|null $userId
     * @param string $name
     * @param string $redirect
     * @param bool $password
     * @param string|null $secret
     * @return \Laravel\Passport\Client
     */
    protected function createExmentClient($userId, $name, $redirect, bool $password, ?string $secret = null)
    {
        $client = Passport::client()->forceFill([
            'user_id' => $userId,
            'name' => $name,
            'secret' => $secret ?? Str::random(40),
            'redirect' => $redirect,
            'personal_access_client' => false,
            'password_client' => $password,
            'api_key_client' => false,
            'revoked' => false,
        ]);
        $client->save();

        return $client;
    }

    /**
     * Store a new client for api key.
     *
     * @param int $userId
     * @param string $name
     * @param bool|string $redirect
     * @param string|null $secret plain secret; random when null
     * @return \Laravel\Passport\Client
     */
    public function createApiKey($userId, $name, $redirect, ?string $secret = null)
    {
        $client = Passport::client()->forceFill([
            'user_id' => $userId,
            'name' => $name,
            'secret' => $secret ?? Str::random(40),
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

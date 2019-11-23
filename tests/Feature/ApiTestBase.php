<?php

namespace Exceedone\Exment\Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\ApiScope;

abstract class ApiTestBase extends TestCase
{
    /**
     * Get Client Id and Secret 
     *
     * @return void
     */
    protected function getClientIdAndSecret(){
        // get client id and secret token
        $client = ApiClient::where('name', Define::API_FEATURE_TEST)->first();

        return [$client->id, $client->secret];
    }

    /**
     * Get Password token
     *
     * @return void
     */
    protected function getPasswordToken($user_code, $password, $scope = []){
        System::clearCache();
        list($client_id, $client_secret) = $this->getClientIdAndSecret();
        
        if(\is_nullorempty($scope)){
            $scope = ApiScope::arrays();
        }

        return $this->post(admin_urls('oauth', 'token'), [
            "grant_type" => "password",
            "client_id" => $client_id,
            "client_secret" =>  $client_secret,
            "username" =>  $user_code,
            "password" =>  $password,
            "scope" =>  implode(' ', $scope),
        ]);
    }

    
    /**
     * Get Admin access token for administrator
     *
     * @return void
     */
    protected function getAdminAccessToken($scope = []){
        $response = $this->getPasswordToken('admin', 'adminadmin', $scope);

        return array_get(json_decode($response->baseResponse->getContent(), true), 'access_token');
    }
    
    /**
     * Get user1 access token for all-edit user
     *
     * @return void
     */
    protected function getUser1AccessToken($scope = []){
        $response = $this->getPasswordToken('user1', 'user1user1', $scope);

        return array_get(json_decode($response->baseResponse->getContent(), true), 'access_token');
    }
    
    /**
     * Get user2 access token for general user
     *
     * @return void
     */
    protected function getUser2AccessToken($scope = []){
        $response = $this->getPasswordToken('user2', 'user2user2', $scope);

        return array_get(json_decode($response->baseResponse->getContent(), true), 'access_token');
    }
}

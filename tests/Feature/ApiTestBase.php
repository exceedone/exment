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
        $client = ApiClient::withoutGlobalScope('only_self')->where('name', Define::API_FEATURE_TEST)->first();

        return [$client->id, $client->secret];
    }

    /**
     * Get Client Id and Secret and Key
     *
     * @return void
     */
    protected function getClientIdAndSecretAndKey(){
        // get client id and secret token
        $client = ApiClient::withoutGlobalScope('only_self')->where('name', Define::API_FEATURE_TEST_APIKEY)->first();

        return [$client->id, $client->secret, $client->client_api_key->key];
    }
    /**
     * Get Password token
     *
     * @return void
     */
    protected function getPasswordToken($user_code, $password, $scope = []){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
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
     * Get API Key 
     *
     * @return void
     */
    protected function getApiKey($scope = []){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        list($client_id, $client_secret, $api_key) = $this->getClientIdAndSecretAndKey();
        
        if(\is_nullorempty($scope)){
            $scope = ApiScope::arrays();
        }

        return $this->post(admin_urls('oauth', 'token'), [
            "grant_type" => "api_key",
            "client_id" => $client_id,
            "client_secret" =>  $client_secret,
            "api_key" =>  $api_key,
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
     * Get Admin access token for administrator. get as api key
     *
     * @return void
     */
    protected function getAdminAccessTokenAsApiKey($scope = []){
        $response = $this->getApiKey($scope);

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
    
    /**
     * Get user access token for target user
     *
     * @return void
     */
    protected function getUserAccessToken($userid, $password, $scope = []){
        $response = $this->getPasswordToken($userid, $password, $scope);

        return array_get(json_decode($response->baseResponse->getContent(), true), 'access_token');
    }
}

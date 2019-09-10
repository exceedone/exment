<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\ApiClientType;
use Laravel\Passport\Client;

/**
 * Extend Laravel\Passport\Client
 * For view Api Setting
 */
class ApiClient extends Client
{
    protected $appends = ['client_type', 'client_type_text'];

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

    public function getClientTypeAttribute(){
        if(boolval($this->password_client)){
            return ApiClientType::PASSWORD_GRANT;
        }
        if(!boolval($this->personal_access_client) && !boolval($this->password_client)){
            return ApiClientType::CLIENT_CREDENTIALS;
        }
    }
    public function getClientTypeTextAttribute(){
        $client_type = $this->client_type;
        switch($client_type){
            case ApiClientType::PASSWORD_GRANT:
                return exmtrans('api.client_type_options.password_grant');
            case ApiClientType::CLIENT_CREDENTIALS:
                return exmtrans('api.client_type_options.client_credentials');
        }
    }
}

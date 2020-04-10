<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\LoginType;

class LoginSetting extends ModelBase
{
    use Traits\DatabaseJsonTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['login_type_text'];
    protected $casts = ['options' => 'json'];
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }

    public function getLoginTypeTextAttribute(){
        $enum = LoginType::getEnum($this->login_type);
        return isset($enum) ? $enum->transKey('login.login_type_options') : null;
    }
    
    public function getProviderNameAttribute(){
        if(!is_nullorempty($name = $this->getOption('oauth_login_provider_name'))){
            return $name;
        }

        return $this->getOption('oauth_login_provider_type');
    }
    
    /**
     * Get OAuth's all settings
     *
     * @return void
     */
    public static function getOAuthSettings(){
        return static::allRecords(function($record){
            return $record->login_type == LoginType::OAUTH;
        });
    }

    /**
     * Get SAML's all settings
     *
     * @return void
     */
    public static function getSamlSettings(){
        return static::allRecords(function($record){
            return $record->login_type == LoginType::SAML;
        });
    }
    
    /**
     * get Socialite Provider
     */
    public static function getSocialiteProvider(string $login_provider)
    {
        $provider = static::firstRecord(function($record) use($login_provider){
            if($record->login_type != LoginType::OAUTH){
                return false;
            }
            
            return $record->getOption('loauth_ogin_provider_name') == $login_provider || $record->getOption('oauth_login_provider_type') == $login_provider;
        });

        //create config
        config(["services.$login_provider.client_id" => $provider->getOption('client_id')]);
        config(["services.$login_provider.client_secret" => $provider->getOption('client_secret')]);


        if (is_null(config("services.$login_provider.redirect"))) {
            $redirect_url = $provider->getOption('oauth_redirect_url') ?? admin_urls("auth", "login", $login_provider, "callback");
            config(["services.$login_provider.redirect" => $redirect_url]);
        }
        
        $scope = $provider->getOption('scope', []);
        return \Socialite::with($login_provider)
            ->scopes($scope)
            //->stateless();
            ;
    }

    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($model) {
            $model->prepareJson('options');
        });
    }

}

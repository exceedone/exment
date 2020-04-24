<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;

class LoginSetting extends ModelBase
{
    use Traits\DatabaseJsonTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['login_type_text'];
    protected $casts = ['options' => 'json', 'active_flg' => 'boolean'];
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }

    public function getLoginTypeTextAttribute(){
        $enum = LoginType::getEnum($this->login_type);
        return isset($enum) ? $enum->transKey('login.login_type_options') : null;
    }
    
    public function getProviderNameAttribute(){
        
        if($this->login_type == LoginType::OAUTH){
            if(!is_nullorempty($name = $this->getOption('oauth_provider_name'))){
                return $name;
            }
    
            return $this->getOption('oauth_provider_type');
        }

        elseif($this->login_type == LoginType::SAML){
            return $this->getOption('saml_name');
        }
    }

    public function getNameIdFormatStringAttribute()
    {
        // create config(copied from setting file)
        $sp_name_id_format_key = $this->getOption('saml_sp_name_id_format');
        if(!isset($sp_name_id_format_key)){
            return '';
        }

        return array_get(Define::SAML_NAME_ID_FORMATS, $sp_name_id_format_key);
    }

    /**
     * get exment login url
     * oauth: admin/auth/login/{providername}
     * saml: admin/saml/login/{providername}
     *
     * @return string
     */
    public function getExmentLoginUrlAttribute() : string
    {
        if($this->login_type == LoginType::OAUTH){
            return admin_urls('auth', 'login', $this->provider_name);
        }
        elseif($this->login_type == LoginType::SAML){
            return admin_urls('saml', 'login', $this->provider_name);   
        }
    }

    /**
     * Get login button
     *
     * @return void
     */
    public function getLoginButton(){
        $provider_name = $this->provider_name;

        // get font owesome
        $hasDefault = in_array($provider_name, LoginProviderType::arrays());
        $display_name = $this->getConfigOption('display_name', 'login_button_label', pascalize($provider_name));

        return [
            'btn_name' => 'btn-'.$provider_name,
            'login_url' => $this->exment_login_url,
            'font_owesome' => $this->getConfigOption('font_owesome', 'login_button_icon', !$hasDefault ? 'fa-sign-in' : "fa-$provider_name"),
            'display_name' => exmtrans('login.login_button_format', ['display_name' => $display_name]),
            'background_color' => $this->getConfigOption('background_color', 'login_button_background_color', !$hasDefault ? '#3c8dbc' : null),
            'font_color' => $this->getConfigOption('font_color', 'login_button_font_color', !$hasDefault ? '#ffffff' : null),
            'background_color_hover' => $this->getConfigOption('background_color_hover', 'login_button_background_color_hover', !$hasDefault ? '#367fa9' : null),
            'font_color_hover' => $this->getConfigOption('font_color_hover', 'login_button_font_color_hover', !$hasDefault ? '#ffffff' : null),
        ];
    }

    /**
     * Get Login Button Option
     *
     * @param [type] $provider_name
     * @param [type] $configKeyName
     * @param [type] $optionKeyName
     * @param [type] $default
     * @return void
     */
    public function getConfigOption($configKeyName, $optionKeyName, $default = null){
        $val = config("services.$this->provider_name.$configKeyName", $this->getOption($optionKeyName));
        return $val ?? $default;
    }
    
    /**
     * Get SSO all settings
     *
     * @return void
     */
    public static function getAllSettings(){
        // if sso_disabled is true, return empty collect
        if(boolval(config('exment.sso_disabled', false))){
            return collect();
        }

        return static::allRecords(function($record){
            return $record->active_flg;
        });
    }

    /**
     * Get OAuth's all settings
     *
     * @return void
     */
    public static function getOAuthSettings(){
        return static::getAllSettings()->filter(function($record){
            return $record->login_type == LoginType::OAUTH;
        });
    }

    /**
     * Get OAuth's setting
     *
     * @return void
     */
    public static function getOAuthSetting($provider_name){
        return static::getOAuthSettings()->first(function($record) use($provider_name){
            return $record->getOption('oauth_provider_name') == $provider_name || $record->getOption('oauth_provider_type') == $provider_name;
        });
    }
    
    /**
     * Get SAML's all settings
     *
     * @return void
     */
    public static function getSamlSettings(){
        return static::getAllSettings()->filter(function($record){
            return $record->login_type == LoginType::SAML;
        });
    }
    
    /**
     * Get SAML's setting
     *
     * @return void
     */
    public static function getSamlSetting($provider_name){
        return static::getSamlSettings()->first(function($record) use($provider_name){
            return $record->getOption('saml_name') == $provider_name;
        });
    }
    
    /**
     * Whether redirect sso page force.
     * System setting "sso_redirect_force" is true and show_default_login_provider is false and active_flg count is 1
     *
     * @return boolean
     */
    public static function getRedirectSSOForceUrl(){
        if(!System::sso_redirect_force()){
            return null;
        }

        if(System::show_default_login_provider()){
            return null;
        }

        $settings = static::getAllSettings();
        if(count($settings) != 1){
            return null;
        }

        return $settings->first()->exment_login_url;
    }

    /**
     * get Socialite Provider
     */
    public static function getSocialiteProvider(string $login_provider)
    {
        $provider = static::getOAuthSetting($login_provider);
        if(!isset($provider)){
            return null;
        }

        //create config
        $config = [
            'client_id' => $provider->getOption('oauth_client_id'),
            'client_secret' => $provider->getOption('oauth_client_secret'),
            'redirect' => $provider->getConfigOption('redirect', 'oauth_redirect_url', \URL::route('oauth_callback', ['provider' => $login_provider])),
        ];
        config(["services.$login_provider" => array_merge(config("services.$login_provider", []), $config)]);

        $scope = $provider->getOption('scope', []);
        return \Socialite::with($login_provider)
            ->scopes($scope)
            ;
    }

    /**
     * get Socialite Provider
     */
    public static function getSamlAuth(string $provider_name)
    {
        if(!class_exists('\\Aacotroneo\\Saml2\\Saml2Auth')){
            //TODO:exception
            throw new \Exception;
        }

        $provider = static::getSamlSetting($provider_name);

        if(!isset($provider)){
            return null;
        }

        // create config(copied from setting file)
        $sp_name_id_format = $provider->name_id_format_string;

        $config = [
            'strict' => true,
            'debug' => config('app.debug', false),

            'idp' => [
                'x509cert' => $provider->getOption('saml_idp_x509'),
                'entityId' => $provider->getOption('saml_idp_entityid'),
                'singleSignOnService' => [
                    'url' => $provider->getOption('saml_idp_sso_url'),
                ],
                'singleLogoutService' => [
                    'url' => $provider->getOption('saml_idp_ssout_url'),
                ],
            ],

            'sp' => [
                'NameIDFormat' => $sp_name_id_format ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                'x509cert' => $provider->getOption('saml_sp_x509'),
                'privateKey' => $provider->getOption('saml_sp_privatekey'),
                'entityId' => $provider->getOption('saml_sp_entityid'),
                'assertionConsumerService' => [
                    'url' => \URL::route('saml_acs', ['provider' => $provider_name]),
                ],
                'singleLogoutService' => [
                    'url' => \URL::route('saml_sls'),
                ],
            ],

            'security' => [
                'nameIdEncrypted' => boolval($provider->getOption('saml_option_name_id_encrypted')) ??  false,
                'authnRequestsSigned' => boolval($provider->getOption('saml_option_authn_request_signed')) ??  false,
                'logoutRequestSigned' => boolval($provider->getOption('saml_option_logout_request_signed')) ??  false,
                'logoutResponseSigned' => boolval($provider->getOption('saml_option_logout_response_signed')) ??  false,
            ],
        ];

        $saml2Auth = new \Aacotroneo\Saml2\Saml2Auth(new \OneLogin\Saml2\Auth($config));
        return $saml2Auth;
    }

    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($model) {
            $model->prepareJson('options');
        });
    }

}

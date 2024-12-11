<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Exceedone\Exment\Services\Login as LoginServiceRoot;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\LoginProviderType;

/**
 * @phpstan-consistent-constructor
 * @property mixed $login_view_name
 * @property mixed $login_type
 * @property mixed $active_flg
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class LoginSetting extends ModelBase
{
    use Traits\DatabaseJsonOptionTrait;
    use Traits\UseRequestSessionTrait;

    protected $guarded = ['id'];
    protected $appends = ['login_type_text'];
    protected $casts = ['options' => 'json', 'active_flg' => 'boolean'];

    public function getLoginTypeTextAttribute()
    {
        $enum = LoginType::getEnum($this->login_type);
        return isset($enum) ? $enum->transKey('login.login_type_options') : null;
    }

    public function getProviderNameAttribute()
    {
        if ($this->login_type == LoginType::OAUTH) {
            if (!is_nullorempty($name = $this->getOption('oauth_provider_name'))) {
                return $name;
            }

            return $this->getOption('oauth_provider_type');
        } elseif ($this->login_type == LoginType::SAML) {
            return $this->getOption('saml_name');
        } elseif ($this->login_type == LoginType::LDAP) {
            return $this->getOption('ldap_name');
        }
    }

    public function getNameIdFormatStringAttribute()
    {
        // create config(copied from setting file)
        $sp_name_id_format_key = $this->getOption('saml_sp_name_id_format');
        if (!isset($sp_name_id_format_key)) {
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
    public function getExmentLoginUrlAttribute(): ?string
    {
        if ($this->login_type == LoginType::OAUTH) {
            return admin_urls('auth', 'login', $this->provider_name);
        } elseif ($this->login_type == LoginType::SAML) {
            return admin_urls('saml', 'login', $this->provider_name);
        }

        return null;
    }

    /**
     * get exment callback default url
     * oauth: admin/auth/login/{providername}/callback
     * saml: admin/saml/login/{providername}/acs
     *
     * @return string
     */
    public function getExmentCallbackUrlAttribute(): string
    {
        if ($this->login_type == LoginType::OAUTH) {
            return $this->getOption('oauth_redirect_url') ?? $this->exment_callback_url_default;
        } elseif ($this->login_type == LoginType::SAML) {
            return $this->exment_callback_url_default;
        }
        return '';
    }

    /**
     * get exment callback url(Default)
     * oauth: admin/auth/login/{providername}/callback
     * saml: admin/saml/login/{providername}/acs
     *
     * @return string
     */
    public function getExmentCallbackUrlDefaultAttribute(): string
    {
        if ($this->login_type == LoginType::OAUTH) {
            return admin_urls("auth/login/{$this->provider_name}/callback");
        } elseif ($this->login_type == LoginType::SAML) {
            return admin_urls("saml/login/{$this->provider_name}/acs");
        }
        return '';
    }

    /**
     * get exment callback url(for test)
     * admin/login_setting/{id}/testcallback
     *
     * @return string
     */
    public function getExmentCallbackUrlTestAttribute(): string
    {
        if ($this->login_type == LoginType::OAUTH) {
            return route('exment.logintest_callback', ['id' => $this->id]);
        } elseif ($this->login_type == LoginType::SAML) {
            return route('exment.logintest_acs', ['id' => $this->id]);
        }
        return '';
    }

    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
    }

    /**
     * Get login button
     *
     * @return array
     */
    public function getLoginButton()
    {
        $provider_name = $this->provider_name;

        // get font owesome
        $hasDefault = in_array($provider_name, LoginProviderType::arrays());
        $display_name = $this->getOption('login_button_label') ?? exmtrans('login.login_button_format', ['display_name' => $this->login_view_name]);

        return [
            'btn_name' => 'btn-'.$provider_name,
            'login_url' => $this->exment_login_url,
            'font_owesome' => $this->getOption('login_button_icon') ?? (!$hasDefault ? 'fa-sign-in' : "fa-$provider_name"),
            'display_name' => $display_name,
            'background_color' => $this->getOption('login_button_background_color') ?? (!$hasDefault ? '#3c8dbc' : null),
            'font_color' => $this->getOption('login_button_font_color') ?? (!$hasDefault ? '#ffffff' : null),
            'background_color_hover' => $this->getOption('login_button_background_color_hover') ?? (!$hasDefault ? '#367fa9' : null),
            'font_color_hover' => $this->getOption('login_button_font_color_hover') ?? (!$hasDefault ? '#ffffff' : null),
        ];
    }

    /**
     * Get SSO all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAllSettings($filterActive = true)
    {
        return static::allRecords(function ($record) use ($filterActive) {
            return !$filterActive || $record->active_flg;
        }, false);
    }

    /**
     * Get SSO (OAuth and SAML) all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getSSOSettings($filterActive = true)
    {
        return static::getOAuthSettings($filterActive)->merge(static::getSamlSettings());
    }

    /**
     * Get OAuth's all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getOAuthSettings($filterActive = true)
    {
        return static::getAllSettings($filterActive)->filter(function ($record) {
            return $record->login_type == LoginType::OAUTH;
        });
    }

    /**
     * Get OAuth's setting
     *
     * @return ?LoginSetting
     */
    public static function getOAuthSetting($provider_name, $filterActive = true)
    {
        return static::getOAuthSettings($filterActive)->first(function ($record) use ($provider_name) {
            return $record->getOption('oauth_provider_name') == $provider_name || $record->getOption('oauth_provider_type') == $provider_name;
        });
    }

    /**
     * Get SAML's all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getSamlSettings($filterActive = true)
    {
        return static::getAllSettings($filterActive)->filter(function ($record) {
            return $record->login_type == LoginType::SAML;
        });
    }

    /**
     * Get SAML's setting
     *
     * @return ?LoginSetting
     */
    public static function getSamlSetting($provider_name, $filterActive = true)
    {
        return static::getSamlSettings($filterActive)->first(function ($record) use ($provider_name) {
            return $record->getOption('saml_name') == $provider_name;
        });
    }

    /**
     * Get Ldap login's all settings
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getLdapSettings($filterActive = true)
    {
        return static::getAllSettings($filterActive)->filter(function ($record) {
            return $record->login_type == LoginType::LDAP;
        });
    }

    /**
     * Get Ldap login's setting
     *
     * @return ?LoginSetting
     */
    public static function getLdapSetting($provider_name, $filterActive = true)
    {
        return static::getLdapSettings($filterActive)->first(function ($record) use ($provider_name) {
            return $record->provider_name == $provider_name;
        });
    }

    /**
     * Whether redirect sso page force.
     * System setting "sso_redirect_force" is true and show_default_login_provider is false and active_flg count is 1
     *
     * @return boolean|null
     */
    public static function getRedirectSSOForceUrl()
    {
        if (!System::sso_redirect_force()) {
            return null;
        }

        if (System::show_default_login_provider()) {
            return null;
        }

        $settings = static::getSSOSettings();
        if (count($settings) != 1) {
            return null;
        }

        return $settings->first()->exment_login_url;
    }

    /**
     * get Socialite Provider
     */
    public static function getSocialiteProvider($login_provider, $isTest = false, ?string $redirectUrl = null)
    {
        if (is_string($login_provider)) {
            $provider_name = $login_provider;
            $provider = static::getOAuthSetting($login_provider, $isTest);
            if (!isset($provider)) {
                return null;
            }
        } else {
            $provider = $login_provider;
            $provider_name = $provider->provider_name;
        }

        // get redirect url
        // (1)config
        // (2)arg string
        // (3) default setting
        $redirectConfigUrl = config("services.{$provider_name}.redirect");
        $redirectUrl = $redirectConfigUrl ?: $redirectUrl ?: ($isTest ? $provider->exment_callback_url_test : $provider->exment_callback_url);

        //create config
        $config = [
            'client_id' => $provider->getOption('oauth_client_id'),
            'client_secret' => $provider->getOption('oauth_client_secret'),
            'redirect' => $redirectUrl,
        ];
        config(["services.$provider_name" => array_merge(config("services.$provider_name", []), $config)]);

        $scope = $provider->getOption('oauth_scope', []);
        $socialiteProvider = \Socialite::with($provider_name)
            ->scopes($scope)
        ;

        // If has custom setting, call custom config.
        if (!is_nullorempty($socialiteProvider) && is_subclass_of($socialiteProvider, \Exceedone\Exment\Auth\ProviderLoginConfig::class)) {
            $socialiteProvider->setLoginCustomConfig($provider);
        }

        return $socialiteProvider;
    }

    /**
     * get Socialite Provider
     */
    public static function getSamlAuth($login_provider, bool $isTest = false)
    {
        if (!class_exists('\\Aacotroneo\\Saml2\\Saml2Auth')) {
            //TODO:exception
            throw new \Exception();
        }

        if (is_string($login_provider)) {
            $provider_name = $login_provider;
            $provider = static::getSamlSetting($provider_name, !$isTest);
        } else {
            $provider_name = $login_provider->provider_name;
            $provider = $login_provider;
        }

        if (!isset($provider)) {
            return null;
        }

        // create config(copied from setting file)
        $sp_name_id_format = $provider->name_id_format_string;

        $config = [
            'strict' => true,
            'debug' => config('app.debug', false),

            'idp' => [
                'x509cert' => LoginServiceRoot\Saml\SamlService::getCerKeysFromFromFile('saml_idp_x509', $provider),
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
                'x509cert' => LoginServiceRoot\Saml\SamlService::getCerKeysFromFromFile('saml_sp_x509', $provider),
                'privateKey' => LoginServiceRoot\Saml\SamlService::getCerKeysFromFromFile('saml_sp_privatekey', $provider),
                'entityId' => $provider->getOption('saml_sp_entityid'),
                'assertionConsumerService' => [
                    'url' => $isTest ? $provider->exment_callback_url_test : $provider->exment_callback_url,
                ],
                'singleLogoutService' => [
                    'url' => $isTest ? null : route('exment.saml_sls'),
                ],
            ],

            'security' => [
                'nameIdEncrypted' => boolval($provider->getOption('saml_option_name_id_encrypted')),
                'authnRequestsSigned' => boolval($provider->getOption('saml_option_authn_request_signed')),
                'logoutRequestSigned' => boolval($provider->getOption('saml_option_logout_request_signed')),
                'logoutResponseSigned' => boolval($provider->getOption('saml_option_logout_response_signed')),
            ],
        ];

        // set proxy vars
        if (boolval($provider->getOption('saml_option_proxy_vars'))) {
            \OneLogin\Saml2\Utils::setProxyVars(true);
        }

        $saml2Auth = new \Aacotroneo\Saml2\Saml2Auth(new \OneLogin\Saml2\Auth($config));
        return $saml2Auth;
    }

    /**
     * Whether use default login form. (Not contain LDAP)
     *
     * @return boolean
     */
    public static function isUseDefaultLoginForm()
    {
        if (boolval(config('exment.custom_login_disabled', false))) {
            return true;
        }

        if (System::show_default_login_provider()) {
            return true;
        }

        if (count(LoginSetting::getAllSettings()) == 0) {
            return true;
        }

        return false;
    }

    /**
     * Get Login Service Class Name
     *
     * @return string
     */
    public function getLoginServiceClassName(): string
    {
        return LoginType::getLoginServiceClassName($this->login_type);
    }

    /**
     * Set Bcrypt Cert or private key
     *
     * @return void
     */
    protected function setBcrypt()
    {
        $keys = ['saml_sp_x509', 'saml_sp_privatekey', 'saml_idp_x509'];
        $originals = jsonToArray($this->getRawOriginal('options'));

        foreach ($keys as $key) {
            $value = $this->getOption($key);
            $original = array_get($originals, $key);

            if (is_nullorempty($value)) {
                continue;
            }

            if ($value == $original) {
                continue;
            }

            if (isset($original) && trydecrypt($original) == $value) {
                $this->setOption($key, $original);
            } else {
                $this->setOption($key, encrypt($value));
            }
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->prepareJson('options');

            $model->setBcrypt();
        });
    }
}

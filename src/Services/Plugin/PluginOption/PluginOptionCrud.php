<?php

namespace Exceedone\Exment\Services\Plugin\PluginOption;

use Exceedone\Exment\Services\Login\OAuth\OAuthService;
use Exceedone\Exment\Model;

class PluginOptionCrud extends PluginOptionBase
{
    /**
     * @param $plugin
     * @param $pluginClass
     * @param $options
     * @phpstan-ignore-next-line
     */
    public function __construct($plugin, $pluginClass, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginClass = $pluginClass;
    }

    protected $plugin;

    protected $pluginClass;

    /**
     * Already getted access token
     *
     * @var string|null
     */
    protected $access_token;


    /**
     * Get auth for oauth.
     *
     * @return Model\LoginSetting|null
     */
    public function getOauthSetting(): ?Model\LoginSetting
    {
        $oauth_id = $this->plugin->getOption('crud_auth_oauth');
        if (is_nullorempty($oauth_id)) {
            return null;
        }

        return Model\LoginSetting::getEloquent($oauth_id);
    }

    /**
     * Login OAuth.
     */
    public function loginOAuth()
    {
        $login_setting = $this->getOauthSetting();
        return OAuthService::loginPluginClud(request(), $login_setting, $this->pluginClass->getFullUrl('oauthcallback'));
    }


    /**
     * Get auth for oauth.
     *
     * @return string|null
     */
    public function getOauthAccessToken(): ?string
    {
        // If already getted, return this.
        if ($this->access_token) {
            return $this->access_token;
        }

        $login_setting = $this->getOauthSetting();
        if (!$login_setting) {
            return null;
        }

        $this->access_token = OAuthService::getAccessTokenFromDB($login_setting);
        return $this->access_token;
    }


    /**
     * Set auth for oauth.
     *
     * @return self
     */
    public function setOauthAccessToken()
    {
        $login_setting = $this->getOauthSetting();
        if (!$login_setting) {
            return $this;
        }

        $access_token = OAuthService::callbackAccessTokenToDB($login_setting, $this->pluginClass->getFullUrl('oauthcallback'));
        $this->access_token = $access_token;

        return $this;
    }

    /**
     * Clear auth for oauth.
     *
     * @return self
     */
    public function clearOauthAccessToken()
    {
        $login_setting = $this->getOauthSetting();
        if (!$login_setting) {
            return $this;
        }

        OAuthService::clearAccessTokenFromDB($login_setting);
        $this->access_token = null;

        return $this;
    }
}

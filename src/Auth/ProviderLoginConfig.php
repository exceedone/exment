<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Model\LoginSetting;

interface ProviderLoginConfig
{
    /**
     * Set setting form for login setting controller for Exment.
     *
     * @param mixed $form
     * @return void
     */
    public function setLoginSettingForm($form);

    /**
     * Append login setting to Socialite.
     * If append parameter to Socialite, please set $this->config.
     * If get custom setting from Exmednt setting. please get $login_setting->getOption('[option key]');
     *
     * @param LoginSetting $login_setting
     * @return void
     */
    public function setLoginCustomConfig(?LoginSetting $login_setting);
}

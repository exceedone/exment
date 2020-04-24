<?php

namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Services\Login\CustomLoginUserBase;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;

/**
 * LdapUser.
 * For Ldap login
 * When get user info from provider, set this model.
 */
class LdapUser extends CustomLoginUserBase
{
    public static function with($login_setting, array $pluginUser){
        $user = new LdapUser;
        $user->provider_name = $login_setting->getOption('ldap_name');
        $user->login_type = LoginType::LDAP;
        $user->login_setting = $login_setting;
        $user->id = array_get($pluginUser, 'id') ?: array_get($pluginUser, 'user_code');

        $user->email = array_get($pluginUser, 'email');
        $user->user_code = array_get($pluginUser, 'user_code');
        $user->user_name = array_get($pluginUser, 'user_name');

        // find key name for search value
        $user->mapping_user_column = $user->login_setting->getOption('mapping_user_column') ?? 'email';
        $user->login_id = $user->{$user->mapping_user_column};
        $user->dummy_password = $user->id;

        return $user;
    }

}

<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\System;

trait AuthTrait
{
    public function getLoginPageData($array = [])
    {
        // whether using sso
        $login_providers = config('exment.login_providers');
        if (!is_null($login_providers)) {
            if (is_string($login_providers)) {
                $login_providers = explode(",", $login_providers);
            }

            // create login provider items for login page
            $login_provider_items = [];
            foreach ($login_providers as $login_provider) {
                $login_provider_items[$login_provider] = [
                    'font_owesome' => config("services.$login_provider.font_owesome", "fa-$login_provider"),
                    'btn_name' => 'btn-'.$login_provider,
                    'display_name' => config("services.$login_provider.display_name", pascalize($login_provider)),
                    'background_color' => config("services.$login_provider.background_color"),
                    'font_color' => config("services.$login_provider.font_color"),
                    'background_color_hover' => config("services.$login_provider.background_color_hover"),
                    'font_color_hover' => config("services.$login_provider.font_color_hover"),
                ];
            }

            $array['login_providers'] = $login_provider_items;
            $array['show_default_login_provider']= config('exment.show_default_login_provider', false);
        } else {
            $array['login_providers'] = [];
            $array['show_default_login_provider']= true;
        }

        $array['site_name'] = System::site_name();
        return $array;
    }
}

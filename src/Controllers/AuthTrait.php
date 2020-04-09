<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;

trait AuthTrait
{
    public function getLoginPageData($array = [])
    {
        $array['site_name'] = System::site_name();

        // get login settings
        $login_settings = LoginSetting::allRecords();

        if (!is_nullorempty($login_settings)) {
            // create login provider items for login page
            $login_provider_items = [];
            foreach($login_settings as $login_setting){
                $provider_name = $login_setting->provider_name;

                $login_provider_items[$provider_name] = [
                    'font_owesome' => $login_setting->getOption("login_button_icon", "fa-$provider_name"),
                    'btn_name' => 'btn-'.$provider_name,
                    'display_name' => $login_setting->getOption("login_button_label", pascalize($provider_name)),
                    'background_color' => $login_setting->getOption("login_button_background_color"),
                    'font_color' => $login_setting->getOption("login_button_font_color"),
                    'background_color_hover' => $login_setting->getOption("login_button_background_color_hover"),
                    'font_color_hover' => $login_setting->getOption("login_button_font_color_hover"),
                ];
            }

            $array['login_providers'] = $login_provider_items;
            $array['show_default_login_provider']= config('exment.show_default_login_provider', false);
        } else {
            $array['login_providers'] = [];
            $array['show_default_login_provider']= true;
        }

        return $array;
    }
}

<?php

namespace Exceedone\Exment\Controllers;
use Exceedone\Exment\Model\System;

trait AuthTrait
{
    public function getLoginPageData($array = []){
        $array['site_name'] = System::site_name();
        return $array;
    }
}

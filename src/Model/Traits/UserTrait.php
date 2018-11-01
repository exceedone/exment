<?php

namespace Exceedone\Exment\Model\Traits;
use Exceedone\Exment\Model;

trait UserTrait
{
    public function login_user()
    {
        return $this->hasOne(Model\LoginUser::class, "base_user_id");
    }

    public function user_setting(){
        return $this->hasOne(Model\UserSetting::class, "user_id");
    }
}

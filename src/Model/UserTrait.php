<?php

namespace Exceedone\Exment\Model;

trait UserTrait
{
    public function login_user()
    {
        return $this->hasOne(LoginUser::class, "base_user_id");
    }

    public function user_setting(){
        return $this->hasOne(UserSetting::class, "user_id");
    }
}

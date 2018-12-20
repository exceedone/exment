<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model;

trait UserTrait
{
    /**
     * get login users.
     * Why "hasMany" not "hasOne" is It can be logged in by multiple providers.
     */
    public function login_users()
    {
        return $this->hasMany(Model\LoginUser::class, "base_user_id");
    }

    public function user_setting()
    {
        return $this->hasOne(Model\UserSetting::class, "user_id");
    }
}

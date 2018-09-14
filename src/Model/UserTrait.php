<?php

namespace Exceedone\Exment\Model;

trait UserTrait
{
    public function login_user()
    {
        return $this->hasOne(\Exceedone\Exment\Model\LoginUser::class, "base_user_id");
    }
}

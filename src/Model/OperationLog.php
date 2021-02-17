<?php

namespace Exceedone\Exment\Model;

class OperationLog extends \Encore\Admin\Auth\Database\OperationLog
{
    protected $appends = ['base_user_id'];

    public function getBaseUserIdAttribute()
    {
        if (isMatchString($this->user_id, 0)) {
            return "0";
        }

        $user = $this->user;
        return $user ? $user->base_user_id : "0";
    }
}

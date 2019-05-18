<?php

namespace Exceedone\Exment\Model;

class UserSetting extends ModelBase
{
    protected $casts = ['settings' => 'json'];
}

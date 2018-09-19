<?php

namespace Exceedone\Exment\Model;

use DB;

class UserSetting extends ModelBase
{
    protected $casts = ['settings' => 'json'];
}

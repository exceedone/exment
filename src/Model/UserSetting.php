<?php

namespace Exceedone\Exment\Model;


class UserSetting extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['settings' => 'json'];
}

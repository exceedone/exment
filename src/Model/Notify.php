<?php

namespace Exceedone\Exment\Model;

class Notify extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
}

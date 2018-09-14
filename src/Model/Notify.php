<?php

namespace Exceedone\Exment\Model;

class Notify extends ModelBase
{
    use AutoSUuid;
    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
}
<?php

namespace Exceedone\Exment\Model;


class Authority extends ModelBase
{
    use AutoSUuid;
    
    protected $casts = ['permissions' => 'json'];

    protected $guarded = ['id'];
}

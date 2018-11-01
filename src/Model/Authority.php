<?php

namespace Exceedone\Exment\Model;


class Authority extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['permissions' => 'json'];

    protected $guarded = ['id'];
}

<?php

namespace Exceedone\Exment\Model;

class Notify extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

}

<?php

namespace Exceedone\Exment\Model;


class DashboardBox extends ModelBase
{
    use AutoSUuid;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];
    
    public function dashboard(){
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
}

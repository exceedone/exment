<?php

namespace Exceedone\Exment\Model;


class Dashboard extends ModelBase
{
    use AutoSUuid;
    
    protected $guarded = ['id'];
    
    public function dashboard_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    public function dashboard_row1_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->where('row_no', 1)
        ->orderBy('row_no')
        ->orderBy('column_no');
    }

    public function dashboard_row2_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->where('row_no', 2)
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
}

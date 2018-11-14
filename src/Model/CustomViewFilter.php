<?php

namespace Exceedone\Exment\Model;

class CustomViewFilter extends ModelBase
{
    protected $guarded = ['id'];
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'view_filter_target');
    }
}

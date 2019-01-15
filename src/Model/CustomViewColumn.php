<?php

namespace Exceedone\Exment\Model;

class CustomViewColumn extends ModelBase
{
    
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;

    protected $guarded = ['id'];
    protected $appends = ['view_column_target'];
    protected $with = ['custom_column'];
    
    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'view_column_target_id');
    }
}

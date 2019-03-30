<?php

namespace Exceedone\Exment\Model;

class CustomCopyColumn extends ModelBase
{
    protected $appends = ['view_column_target'];
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\CustomViewColumnTrait;

    public function custom_copy()
    {
        return $this->belongsTo(CustomValueCopy::class, 'custom_copy_id');
    }
    
    public function from_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'from_column_target_id');
    }
    
    public function to_custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'to_column_target_id');
    }
    
    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getFromViewColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('from_column_type', 'from_column_target_id');
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setFromViewColumnTargetAttribute($view_column_target)
    {
        $this->setViewColumnTarget($view_column_target, 'from_column_type', 'from_column_target_id');
    }

    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getToViewColumnTargetAttribute()
    {
        return $this->getViewColumnTarget('to_column_type', 'to_column_target_id');
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setToViewColumnTargetAttribute($view_column_target)
    {
        $this->setViewColumnTarget($view_column_target, 'to_column_type', 'to_column_target_id');
    }
}

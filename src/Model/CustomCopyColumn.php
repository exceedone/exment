<?php

namespace Exceedone\Exment\Model;


class CustomCopyColumn extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public function custom_copy(){
        return $this->belongsTo(CustomValueCopy::class, 'custom_copy_id');
    }
    
    public function from_custom_column(){
        return $this->belongsTo(CustomColumn::class, 'from_custom_column_id')
            ;
    }
    
    public function to_custom_column(){
        return $this->belongsTo(CustomColumn::class, 'to_custom_column_id')
            ;
    }
}

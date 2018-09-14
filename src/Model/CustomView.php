<?php

namespace Exceedone\Exment\Model;


class CustomView extends ModelBase
{    
    use AutoSUuid;

    protected $guarded = ['id', 'suuid'];

    public function custom_table(){
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_view_columns(){
        return $this->hasMany(CustomViewColumn::class, 'custom_view_id')->orderBy('order');
    }

    public function custom_view_filters(){
        return $this->hasMany(CustomViewFilter::class, 'custom_view_id');
    }

    public function deletingChildren(){
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
    }

    protected static function boot() {
        parent::boot();
        
        // delete event
        static::deleting(function($model) {
            // Delete items
            $model->deletingChildren();
        });
    }
}

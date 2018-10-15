<?php

namespace Exceedone\Exment\Model;


class CustomFormBlock extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['options' => 'json'];

    public function custom_form(){
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function custom_form_columns(){
        return $this->hasMany(CustomFormColumn::class, 'custom_form_block_id');
    }

    public function target_table(){
        return $this->belongsTo(CustomTable::class, 'form_block_target_table_id');
    }
    
    public function deletingChildren(){
        $this->custom_form_columns()->delete();
    }

    protected static function boot() {
        parent::boot();
        
        static::deleting(function($model) {
            $model->deletingChildren();
        });
    }
}

<?php

namespace Exceedone\Exment\Model;


class CustomForm extends ModelBase
{
    use AutoSUuid;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    public function custom_table(){
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_blocks(){
        return $this->hasMany(CustomFormBlock::class, 'custom_form_id');
    }
    
    public function custom_form_columns(){
        return $this->hasManyThrough(CustomFormColumn::class, CustomFormBlock::class, 'custom_form_id', 'custom_form_block_id');
    }
    
    public function deletingChildren(){
        foreach($this->custom_form_blocks as $item){
            $item->custom_form_columns()->delete();
        }
    }

    protected static function boot() {
        parent::boot();
        
        static::deleting(function($model) {
            $model->deletingChildren();
            $model->custom_form_blocks()->delete();
            $model->custom_form_block_target_tables()->delete();
        });
    }
}

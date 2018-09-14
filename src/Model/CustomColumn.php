<?php

namespace Exceedone\Exment\Model;


class CustomColumn extends ModelBase
{
    use AutoSUuid;
    
    protected $casts = ['options' => 'json'];

    protected $guarded = ['id', 'suuid'];

    public function custom_table(){
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_form_columns(){
        return $this->hasMany(CustomFormColumn::class, 'form_column_target_id')
            ->where('form_column_type', Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN);
    }

    public function deletingChildren(){
        $this->custom_form_columns()->delete();
    }

    protected static function boot() {
        parent::boot();
        
        // delete event
        static::deleting(function($model) {
            // Delete items
            $model->deletingChildren();

            // execute alter column
            alterColumn($model->custom_table->table_name, $model->column_name, true);
        });
    }
}

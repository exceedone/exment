<?php

namespace Exceedone\Exment\Model;
use Exceedone\Exment\Enums\MenuType;

getCustomTableTrait();

class CustomTable extends ModelBase
{
    use Traits\CustomTableTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\CustomTableDynamicTrait; // CustomTableDynamicTrait:Dynamic Creation trait it defines relationship.
    use Traits\AutoSUuidTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $casts = ['options' => 'json'];

    protected $guarded = ['id', 'suuid', 'system_flg'];

    public function custom_columns()
    {
        return $this->hasMany(CustomColumn::class, 'custom_table_id');
    }
    public function custom_views()
    {
        return $this->hasMany(CustomView::class, 'custom_table_id')
            ->orderBy('view_type')
            ->orderBy('id');
    }
    public function custom_forms()
    {
        return $this->hasMany(CustomForm::class, 'custom_table_id');
    }
    public function custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'parent_custom_table_id');
    }
    
    public function child_custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'child_custom_table_id');
    }
    
    public function from_custom_copies()
    {
        return $this->hasMany(CustomCopy::class, 'from_custom_table_id');
    }
    
    public function custom_form_block_target_tables()
    {
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }
    
    public function getOption($key)
    {
        return $this->getJson('options', $key);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    
    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        foreach ($this->custom_columns as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_forms as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_block_target_tables as $item) {
            $item->deletingChildren();
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
            
            $model->custom_form_block_target_tables()->delete();
            $model->child_custom_relations()->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();

            // delete menu
            Menu::where('menu_type', MenuType::TABLE)->where('menu_target', $model->id)->delete();
        });
    }
}

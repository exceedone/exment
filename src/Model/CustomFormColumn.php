<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Illuminate\Database\Eloquent\Builder;

class CustomFormColumn extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\DatabaseJsonTrait;
    
    protected $casts = ['options' => 'json'];
    protected $appends = ['form_column_target']
    ;
    public function custom_form_block()
    {
        return $this->belongsTo(CustomFormBlock::class, 'custom_form_block_id');
    }

    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'form_column_target_id');
    }
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
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
    
    protected function getFormColumnTargetAttribute(){
        if($this->form_column_target_id == FormColumnType::SYSTEM){
            return collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($value) {
                return array_get($value, 'id') == $this->form_column_target_id;
            })['name'] ?? null;
        }
        elseif($this->form_column_target_id == FormColumnType::COLUMN){
            return $this->view_column_target_id;
        }
        elseif($this->form_column_target_id == FormColumnType::OTHER){
            return collect(FormColumnType::OTHER_TYPE())->first(function ($value) {
                return array_get($value, 'id') == $this->form_column_target_id;
            })['column_name'] ?? null;
        }
        return null;
    }

    protected static function boot()
    {
        parent::boot();

        // Order by name ASC
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('order', 'asc');
        });
    }
}

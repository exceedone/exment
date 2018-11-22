<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Builder;

class CustomFormColumn extends ModelBase
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use Traits\DatabaseJsonTrait;
    
    protected $casts = ['options' => 'json'];

    public function custom_form_block()
    {
        return $this->belongsTo(CustomFormBlock::class, 'custom_form_block_id');
    }

    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'form_column_target_id');
    }
    
    public function getOption($key)
    {
        return $this->getJson('options', $key);
    }
    public function setOption($key, $val = null)
    {
        return $this->setJson('options', $key, $val);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
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

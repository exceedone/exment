<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Builder;

class CustomFormColumn extends ModelBase
{
    protected $casts = ['options' => 'json'];

    public function custom_form_block()
    {
        return $this->belongsTo(CustomFormBlock::class, 'custom_form_block_id');
    }

    public function custom_column()
    {
        return $this->belongsTo(CustomColumn::class, 'form_column_target_id');
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

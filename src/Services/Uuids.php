<?php

namespace Exceedone\Exment\Services;

use Webpatser\Uuid\Uuid;

trait Uuids
{
    /**
     * Boot function from laravel.
     */
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (is_null($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Uuid::generate()->string;
            }
        });
    }
}

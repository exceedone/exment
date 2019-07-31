<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Facades\Admin;

class ModelBase extends Model
{
    protected $guarded = ['id'];
    
    public function getCreatedUserAttribute()
    {
        return $this->getUser('created_user_id');
    }
    public function getUpdatedUserAttribute()
    {
        return $this->getUser('updated_user_id');
    }

    public function getCreatedUserTagAttribute()
    {
        return $this->getUser('created_user_id', true);
    }
    public function getUpdatedUserTagAttribute()
    {
        return $this->getUser('updated_user_id', true);
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return false;
    }

    public static function getTableName()
    {
        return with(new static)->getTable();
    }

    /**
    *
    * @return void
    */
    protected static function boot()
    {
        parent::boot();

        ///// add created_user_id, updated_user_id
        static::creating(function ($model) {
            static::setUser($model, ['created_user_id', 'updated_user_id']);
        });
        static::updating(function ($model) {
            static::setUser($model, ['updated_user_id']);
        });
    }

    /**
     * set id to $columns
     */
    protected static function setUser($model, $columns = [])
    {
        $user = Admin::user() ?? null;
        if (!isset($user)) {
            return;
        }
        $base_user = $user->base_user;
        if (!isset($base_user)) {
            return;
        }
        $id = $base_user->id ?? null;
        if (!isset($id)) {
            return;
        }
        foreach ($columns as $column) {
            $model->{$column} = $id;
        }
    }
    
    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquentDefault($obj, $withs = [], $query_key = 'id')
    {
        if (!isset($obj)) {
            return null;
        }
        
        // get table
        $obj = static::allRecords(function ($table) use ($query_key, $obj) {
            return array_get($table, $query_key) == $obj;
        })->first();

        if (!isset($obj)) {
            return null;
        }

        if (count($withs) > 0) {
            $obj->load($withs);
        }

        return $obj;
    }

    /**
     * get user from id
     */
    protected function getUser($column, $link = false)
    {
        return getUserName($this->{$column}, $link);
    }
}

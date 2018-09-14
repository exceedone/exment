<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

//trait LambdasAsMethods
//{
//    public function __call($name, $args)
//    {
//        return call_user_func_array($this->$name, $args);
//    }
//}

class ModelBase extends Model
{
    protected $guarded = ['id'];

    public static function getTableName()
    {
        return with(new static)->getTable();
    }
}

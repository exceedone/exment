<?php

namespace Exceedone\Exment\Enums;

class SystemColumn extends EnumBase
{
    use EnumOptionTrait;

    const ID = 'id';
    const SUUID = 'suuid';
    const PARENT_ID = 'parent_id';
    const PARENT_TYPE = 'parent_type';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const DELETED_AT = 'deleted_at';
    const CREATED_USER = 'created_user';
    const UPDATED_USER = 'updated_user';
    const DELETED_USER = 'deleted_user';
    
    protected static $options = [
        'id' => ['id' => 1, 'name' => 'id', 'default' => true, 'order' => 1, 'header' => true],
        'suuid' => ['id' => 2, 'name' => 'suuid', 'default' => false, 'order' => 2, 'header' => true],
        'parent_id' => ['id' => 3, 'name' => 'parent_id', 'default' => false, 'order' => 3, 'header' => true],
        'parent_type' => ['id' => 4, 'name' => 'parent_type', 'default' => false, 'order' => 4, 'header' => true],
        'created_at' => ['id' => 96, 'type' => 'datetime', 'name' => 'created_at', 'default' => true, 'order' => 81, 'footer' => true],
        'updated_at' => ['id' => 97, 'type' => 'datetime', 'name' => 'updated_at', 'default' => true, 'order' => 82, 'footer' => true],
        'deleted_at' => ['id' => 101, 'type' => 'datetime', 'name' => 'deleted_at', 'default' => true, 'order' => 83, 'header' => true],
        'created_user' => ['id' => 98, 'type' => 'user', 'name' => 'created_user', 'default' => false, 'order' => 91, 'footer' => true],
        'updated_user' => ['id' => 99, 'type' => 'user', 'name' => 'updated_user', 'default' => false, 'order' => 92, 'footer' => true],
        'deleted_user' => ['id' => 102, 'type' => 'user', 'name' => 'deleted_user', 'default' => false, 'order' => 93, 'footer' => true],
    ];

    public function id(){
        return array_get($this->option(), 'id');
    }

    public function name(){
        return $this->lowerKey();
    }
    
    public static function getEnum($value){
        $enum = parent::getEnum($value);
        if(isset($enum)){
            return $enum;
        }

        foreach ($options as $key => $v) {
            if(array_get($v, 'id') == $value){
                return parent::getEnum($key);
            }
        }
        return null;
    }
}

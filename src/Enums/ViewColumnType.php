<?php

namespace Exceedone\Exment\Enums;

class ViewColumnType extends EnumBase
{
    const COLUMN = 0;
    const SYSTEM = 1;
    const PARENT_ID = 2;
    const CHILD_SUM = 3;

    public static function SYSTEM_OPTIONS(){
        return [
            ['id' => 1, 'name' => 'id', 'default' => true, 'order' => 1, 'header' => true],
            ['id' => 2, 'name' => 'suuid', 'default' => false, 'order' => 2, 'header' => true],
            ['id' => 96, 'name' => 'created_at', 'default' => true, 'order' => 96, 'footer' => true],
            ['id' => 97, 'name' => 'updated_at', 'default' => true, 'order' => 97, 'footer' => true],
            ['id' => 98, 'name' => 'created_user', 'default' => false, 'order' => 98, 'footer' => true],
            ['id' => 99, 'name' => 'updated_user', 'default' => false, 'order' => 99, 'footer' => true],
        ];
    }
}

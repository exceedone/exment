<?php

namespace Exceedone\Exment\Enums;

class CustomFormColumnType extends EnumBase
{
    const SYSTEM = 'system';
    const COLUMN = 'column';
    const OTHER = 'other';
    
    public static function OTHER_TYPE(){
        return [
            1 => ['id' => 1, 'column_name' => 'header'],
            2 => ['id' => 2, 'column_name' => 'explain'],
            3 => ['id' => 3, 'column_name' => 'html'],
        ];
    }
}

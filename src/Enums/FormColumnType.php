<?php

namespace Exceedone\Exment\Enums;

class FormColumnType extends EnumBase
{
    const COLUMN = '0';
    const SYSTEM = '1';
    const OTHER = '99';
    
    public static function OTHER_TYPE(){
        return [
            1 => ['id' => 1, 'column_name' => 'header'],
            2 => ['id' => 2, 'column_name' => 'explain'],
            3 => ['id' => 3, 'column_name' => 'html'],
        ];
    }
}

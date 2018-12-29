<?php

namespace Exceedone\Exment\Enums;

class FormColumnType extends EnumBase
{
    use EnumOptionTrait;
    
    const COLUMN = '0';
    const SYSTEM = '1';
    const OTHER = '99';
    
    protected static $options = [
        1 => ['id' => 1, 'column_name' => 'header'],
            2 => ['id' => 2, 'column_name' => 'explain'],
            3 => ['id' => 3, 'column_name' => 'html'],
    ];
}

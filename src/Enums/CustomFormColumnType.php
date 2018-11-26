<?php

namespace Exceedone\Exment\Enums;

class CustomFormColumnType extends EnumBase
{
    const SYSTEM = 'system';
    const COLUMN = 'column';
    const OTHER = 'other';
    
    public const CUSTOM_FORM_COLUMN_TYPE_OTHER_TYPE = [
        1 => ['id' => 1, 'column_name' => 'header'],
        2 => ['id' => 2, 'column_name' => 'explain'],
        3 => ['id' => 3, 'column_name' => 'html'],
    ];

}

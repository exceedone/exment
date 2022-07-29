<?php

namespace Exceedone\Exment\Enums;

class DatabaseDataType extends EnumBase
{
    public const TYPE_INTEGER = "0";
    public const TYPE_DECIMAL = "1";
    public const TYPE_STRING = "2";
    public const TYPE_DATE = "3";
    public const TYPE_DATETIME = "4";
    public const TYPE_TIME = "5";

    // for select multiple type
    public const TYPE_STRING_MULTIPLE = "6";
}

<?php

namespace Exceedone\Exment\Enums;

class SearchType extends EnumBase
{
    const SELF = 0;
    const ONE_TO_MANY = 1;
    const MANY_TO_MANY = 2;
    const SELECT_TABLE = 3;
}

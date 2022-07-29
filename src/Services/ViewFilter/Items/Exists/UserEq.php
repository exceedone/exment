<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Enums\FilterOption;

class UserEq extends ExistsBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_EQ;
    }

    protected function isExists(): bool
    {
        return true;
    }
}

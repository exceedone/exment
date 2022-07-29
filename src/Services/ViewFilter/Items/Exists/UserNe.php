<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Enums\FilterOption;

class UserNe extends ExistsBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NE;
    }

    protected function isExists(): bool
    {
        return false;
    }
}

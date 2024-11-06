<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Enums\FilterOption;

class UserNe extends UserEq
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NE;
    }

    protected function isExists(): bool
    {
        return false;
    }

    public function compareValue($value, $conditionValue): bool
    {
        return !parent::compareValue($value, $conditionValue);
    }
}

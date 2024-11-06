<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Enums\FilterOption;

class SelectNotExists extends SelectExists
{
    public static function getFilterOption()
    {
        return FilterOption::SELECT_NOT_EXISTS;
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

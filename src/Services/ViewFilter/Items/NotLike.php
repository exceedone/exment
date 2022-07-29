<?php

namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NotLike extends ViewFilter\LikeBase
{
    public static function getFilterOption()
    {
        return FilterOption::NOT_LIKE;
    }

    protected function isLike(): bool
    {
        return false;
    }


    /**
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue): bool
    {
        return is_null($value) || is_null($conditionValue) || (strpos(strval($value), strval($conditionValue)) === false);
    }
}

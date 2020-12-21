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

    protected function isLike() : bool
    {
        return false;
    }
}

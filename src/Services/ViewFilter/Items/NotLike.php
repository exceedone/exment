<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FilterSearchType;

class NotLike extends ViewFilter\LikeBase
{
    public static function getFilterOption(){
        return FilterOption::NOT_LIKE;
    }

    protected function isLike() : bool{
        return false;
    }
}

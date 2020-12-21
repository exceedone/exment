<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FilterSearchType;

class Like extends ViewFilter\LikeBase
{
    public static function getFilterOption(){
        return FilterOption::LIKE;
    }

    
    protected function isLike() : bool{
        return true;
    }
}

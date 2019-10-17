<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;

class WorkflowItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return array_get(FilterOption::FILTER_OPTIONS(), FilterType::SELECT);
    }
}

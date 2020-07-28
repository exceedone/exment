<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\SystemColumn;

class WorkflowItem extends SystemItem implements ConditionItemInterface
{
    public function getFilterOption()
    {
        $target = explode('?', $this->target)[0];
        return array_get(FilterOption::FILTER_OPTIONS(), $target == SystemColumn::WORKFLOW_STATUS ? FilterType::WORKFLOW : FilterType::WORKFLOW_WORK_USER);
    }
}

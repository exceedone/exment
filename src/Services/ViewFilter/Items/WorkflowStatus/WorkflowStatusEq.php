<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\WorkflowStatus;

use Exceedone\Exment\Enums\FilterOption;

class WorkflowStatusEq extends WorkflowStatusBase
{
    /**
     * @return int|string
     */
    public static function getFilterOption()
    {
        return FilterOption::WORKFLOW_EQ_STATUS;
    }

    protected function isExists(): bool
    {
        return true;
    }
}

<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\WorkflowStatus;

use Exceedone\Exment\Enums\FilterOption;

class WorkflowStatusNe extends WorkflowStatusBase
{
    /**
     * @return int|string
     */
    public static function getFilterOption()
    {
        return FilterOption::WORKFLOW_NE_STATUS;
    }



    protected function isExists(): bool
    {
        return false;
    }
}

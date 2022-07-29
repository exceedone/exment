<?php

namespace Exceedone\Exment\ConditionItems;

use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Enums\ConditionTypeDetail;

abstract class ConditionDetailBase extends ConditionItemBase
{
    /**
     * get query key Name for display
     *
     * @return string|null
     */
    public function getQueryKey(Condition $condition): ?string
    {
        $condition_type = ConditionTypeDetail::getEnum($condition->target_column_id);
        if (!isset($condition_type)) {
            return null;
        }

        return $condition_type->getKey();
    }
}

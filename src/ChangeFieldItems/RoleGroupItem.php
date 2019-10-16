<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Enums\ViewColumnFilterType;

class RoleGroupItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return array_get(ViewColumnFilterOption::VIEW_COLUMN_FILTER_OPTIONS(), ViewColumnFilterType::SELECT);
    }
}

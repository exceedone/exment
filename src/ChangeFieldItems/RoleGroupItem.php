<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FilterType;

class RoleGroupItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return $this->getFilterOptionConditon();
    }
}

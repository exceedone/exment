<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Enums\ViewColumnFilterType;

class RoleGroupItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return $this->getFilterOptionConditon();
    }
}

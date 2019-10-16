<?php

namespace Exceedone\Exment\ChangeFieldItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ConditionTypeDetail;

class OrganizationItem extends ChangeFieldItem
{
    public function getFilterOption(){
        return $this->getFilterOptionConditon();
    }
}

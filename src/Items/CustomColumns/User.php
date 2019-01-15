<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class User extends SelectTable 
{
    public function __construct($custom_column, $custom_value){
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(SystemTableName::USER);
    }
}

<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class User extends SelectTable
{
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(SystemTableName::USER);
    }
    
    /**
     * Get default value. Only avaiable form input.
     *
     * @return mixed
     */
    public function defaultForm(){
        if(!is_null($default = parent::defaultForm())){
            return $default;
        }
        if(!is_null($default = $this->custom_column->getOption('login_user_default'))){
            return $default;
        }

        return null;
    }
}

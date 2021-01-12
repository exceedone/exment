<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

class Organization extends SelectTable
{
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
    }


    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        parent::setCustomColumnOptionForm($form);

        $form->switchbool('showing_all_user_organizations', exmtrans("custom_column.options.showing_all_user_organizations"))
            ->help(exmtrans("custom_column.help.showing_all_user_organizations"))
            ->default('0');
    }
}

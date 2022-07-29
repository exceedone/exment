<?php

namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Exceedone\Exment\Model\CustomTable;

/**
 */
class OneToMany extends RelationBase
{
    public static function getBlockLabelHeader(CustomTable $custom_table)
    {
        return exmtrans('custom_form.table_one_to_many_label') . $custom_table->table_view_name;
    }
}

<?php
namespace Exceedone\Exment\Services\FormSetting\FormBlock;

/**
 */
class OneToMany extends RelationBase
{
    public static function getBlockLabelHeader()
    {
        return exmtrans('custom_form.table_one_to_many_label');
    }
}

<?php

namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Collection;

/**
 */
class ManyToMany extends RelationBase
{
    public static function getBlockLabelHeader(CustomTable $custom_table)
    {
        return exmtrans('custom_form.table_many_to_many_label') . $custom_table->table_view_name;
    }


    /**
     * Get suggest items
     *
     * @return Collection
     */
    public function getSuggestItems()
    {
        //return empty collection
        return collect();
    }
}

<?php
namespace Exceedone\Exment\Services\FormSetting\FormBlock;

/**
 */
class ManyToMany extends RelationBase
{
    public static function getBlockLabelHeader()
    {
        return exmtrans('custom_form.table_many_to_many_label');
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

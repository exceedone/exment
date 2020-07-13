<?php

namespace Exceedone\Exment\DataItems;

use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\FormBlockType;

trait DataTrait
{
    /**
     * get relation name etc for form block
     */
    protected function getRelationName($custom_form_block)
    {
        $target_table = $custom_form_block->target_table;
        // get label hasmany
        $block_label = $custom_form_block->form_block_view_name;
        if (!isset($block_label)) {
            $enum = FormBlockType::getEnum(array_get($custom_form_block, 'form_block_type'));
            $block_label = exmtrans("custom_form.table_".$enum->lowerKey()."_label") . $target_table->table_view_name;
        }
        // get form columns count
        $form_block_options = array_get($custom_form_block, 'options', []);
        $relation_name = CustomRelation::getRelationNameByTables($this->custom_table, $target_table);

        return [$relation_name, $block_label];
    }
}

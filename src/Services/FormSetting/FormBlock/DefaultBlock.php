<?php

namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\FormBlockType;

/**
 */
class DefaultBlock extends BlockBase
{
    public static function getBlockLabelHeader(CustomTable $custom_table)
    {
        return exmtrans('custom_form.table_default_label') . $custom_table->table_view_name;
    }


    /**
     * Get deafult block for create
     *
     * @return self
     */
    public static function getDefaultBlock(CustomTable $custom_table)
    {
        $block = new CustomFormBlock();
        $block->id = null;
        $block->form_block_type = FormBlockType::DEFAULT;
        $block->form_block_target_table_id = $custom_table->id;
        $block->label = static::getBlockLabelHeader($custom_table);
        $block->form_block_view_name = $block->label;
        $block->available = 1;
        $block->options = [];
        $block->custom_form_columns = [];

        return new self($block, $custom_table);
    }
}

<?php

namespace Exceedone\Exment\Services\FormSetting\FormBlock;

use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;

abstract class RelationBase extends BlockBase
{
    /**
     * based table
     *
     * @var CustomRelation
     */
    protected $custom_relation;

    protected function setCustomRelation(CustomRelation $custom_relation)
    {
        $this->custom_relation = $custom_relation;
        return $this;
    }

    /**
     * Get default block for create
     *
     * @param CustomTable $custom_table
     * @param CustomRelation $custom_relation
     * @return static
     */
    public static function getDefaultBlock(CustomTable $custom_table, CustomRelation $custom_relation): self
    {
        // get classname...
        $classname = isMatchString($custom_relation->relation_type, RelationType::ONE_TO_MANY) ? OneToMany::class : ManyToMany::class;

        $block = new CustomFormBlock();
        $block->id = null;
        $block->form_block_type = $custom_relation->relation_type;
        $block->form_block_target_table_id = $custom_relation->child_custom_table_id;
        $block->label = $classname::getBlockLabelHeader($custom_relation->child_custom_table);
        $block->form_block_view_name = $block->label;
        $block->available = 0;
        $block->options = [
            'hasmany_type' => null
        ];

        /** @phpstan-ignore-next-line */
        return BlockBase::make($block, $custom_table)->setCustomRelation($custom_relation);
    }
}

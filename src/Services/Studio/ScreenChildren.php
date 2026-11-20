<?php

namespace Exceedone\Exment\Services\Studio;

use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;

/**
 * Studio, phase 3: the details of a new screen ("明細", like a kintone subtable).
 *
 * An order has its lines; a visit report has its visitors. Each detail of a
 * screen becomes a child table of the new table (one to many), with its own
 * columns, form and view, and a block of the form of the parent that enters
 * the rows of the child as a table (hasmany_type 1) under the items of the
 * parent. The data page and the screen JSON (a "one_to_many" block) already
 * know such a block.
 */
class ScreenChildren
{
    public const MAX_CHILDREN = 5;

    /**
     * The posted details, each read as a screen of its own (BlankFormBuilder::normalize).
     * A detail is named after its parent: "<parent>_d1", "<parent>_d2"...
     *
     * @param mixed $children JSON string or array of {label, fields}
     * @return array<int, array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array{label: string, column_name: string, type: string, required: bool, choices: string, source: ?int, target_table: ?int, formula: string, calc_formula: ?string, formula_error: ?string}>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>}>
     */
    public static function normalize($children, string $parentTableName): array
    {
        if (is_string($children)) {
            $children = json_decode($children, true);
        }
        $result = [];
        foreach (is_array($children) ? array_values($children) : [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $result[] = BlankFormBuilder::normalize([
                'table_view_name' => array_get($child, 'label', ''),
                'table_name' => substr($parentTableName, 0, BlankFormBuilder::MAX_NAME_LENGTH - 3) . '_d' . ($index + 1),
                'fields' => array_get($child, 'fields', []),
            ]);
        }
        return $result;
    }

    /**
     * The mistakes of the details, by "children.N.<key of the screen>".
     *
     * @param array<int, array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array{label: string, column_name: string, type: string, required: bool, choices: string, source: ?int, target_table: ?int, formula: string, calc_formula: ?string, formula_error: ?string}>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>}> $children
     * @return array<string, string>
     */
    public static function validate(array $children): array
    {
        if (count($children) > static::MAX_CHILDREN) {
            return ['children' => sprintf(exmtrans('studio.error.too_many_children'), static::MAX_CHILDREN)];
        }
        $errors = [];
        foreach ($children as $index => $child) {
            foreach (BlankFormBuilder::validate($child) as $key => $message) {
                $errors["children.{$index}.{$key}"] = $message;
            }
        }
        return $errors;
    }

    /**
     * Make the child tables, their relations to the parent, and their blocks
     * in the form of the parent. When one fails, the child tables made so far
     * are deleted and the error goes on (the caller deletes the parent).
     *
     * @param array<int, array{table_view_name: string, table_name: string, menu_parent_id: ?int, fields: array<int, array{label: string, column_name: string, type: string, required: bool, choices: string, source: ?int, target_table: ?int, formula: string, calc_formula: ?string, formula_error: ?string}>, layout: array{rows: array<int, array<int, int>>, places: array<int, array{row: int, column: int}>}, conditions: array<int, array{target: int, operator: string, value: string}>}> $children
     * @return array<int, CustomTable> the child tables made
     */
    public static function build(CustomTable $parent, array $children): array
    {
        $made = [];
        try {
            foreach ($children as $child) {
                $table = BlankFormBuilder::build($child);
                $made[] = $table;

                $relation = new CustomRelation();
                $relation->parent_custom_table_id = $parent->id;
                $relation->child_custom_table_id = $table->id;
                $relation->relation_type = RelationType::ONE_TO_MANY;
                $relation->save();

                static::addBlock($parent, $table);
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($made) as $table) {
                $table->delete();
            }
            throw $e;
        }
        System::clearRequestSession();
        return $made;
    }

    /**
     * The block of the form of the parent that enters the rows of a child as a table.
     */
    protected static function addBlock(CustomTable $parent, CustomTable $child): void
    {
        System::clearRequestSession();
        $form = CustomForm::getDefault($parent);

        $block = new CustomFormBlock();
        $block->custom_form_id = $form->id;
        $block->form_block_type = FormBlockType::ONE_TO_MANY;
        $block->form_block_target_table_id = $child->id;
        $block->form_block_view_name = $child->table_view_name;
        $block->available = true;
        $block->setOption('hasmany_type', '1');
        $block->save();

        $order = 1;
        foreach ($child->custom_columns()->get() as $column) {
            $formColumn = new CustomFormColumn();
            $formColumn->custom_form_block_id = $block->id;
            $formColumn->form_column_type = FormColumnType::COLUMN;
            $formColumn->form_column_target_id = $column->id;
            $formColumn->order = $order++;
            $formColumn->row_no = 1;
            $formColumn->column_no = 1;
            $formColumn->width = 2;
            $formColumn->save();
        }
    }
}

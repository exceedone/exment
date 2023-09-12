<?php

namespace Exceedone\Exment\DataItems\Grid;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\RelationType;
use Encore\Admin\Form;
Use Encore\Admin\Widgets\Table;

class ExpansionGrid extends DefaultGrid
{
    /**
     * set laravel-admin grid column specific setting for expand grid
     */
    protected function setGridColumn($grid_column, $custom_view_column)
    {
        if (isset($custom_view_column->child_table_id)) {
            $child_table_id = $custom_view_column->child_table_id;
            $relation = CustomRelation::getRelationByParentChild($this->custom_table, $child_table_id, RelationType::ONE_TO_MANY);
            if (!$relation) {
                return;
            }
            $child_view = CustomView::getAllData($child_table_id);

            $grid_column->expand(function ($model) use($relation, $child_view) {
                $child_values = [];
                $child_expand_count = config('exment.max_child_expand_count', 10);

                $children_values = $model->getChildrenValues($relation)->take($child_expand_count);
                foreach ($children_values as $children_value) {
                    $child_values[] = $child_view->custom_view_columns->map(function($child_view_column) use($child_view, $children_value){
                        $item = $child_view_column->column_item;
                        if (!isset($item)) {
                            return null;
                        }
                        $item = $item->options([
                                'grid_column' => true,
                                'view_pivot_column' => $child_view_column->view_pivot_column_id ?? null,
                                'view_pivot_table' => $child_view_column->view_pivot_table_id ?? null,
                                'header_align' => $child_view->header_align ?? null,
                            ]);
                        return $item->setCustomValue($children_value)->html();
                    });
                }

                $child_labels = $child_view->custom_view_columns->map(function($child_view_column) {
                    $item = $child_view_column->column_item;
                    if (!isset($item)) {
                        return null;
                    }
                    $item = $item->label(array_get($child_view_column, 'view_column_name'));
                    return $item->label();
                });
            
                return new Table($child_labels, $child_values);
            });
        }
    }

    /**
     * Set custom view columns form. For controller.
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @return void
     */
    public static function setViewForm($view_kind_type, $form, $custom_table, array $options = [])
    {
        static::setViewInfoboxFields($form);

        $form->hasManyTable('custom_view_columns', exmtrans("custom_view.custom_view_columns"), function ($form) use ($custom_table) {
            $targetOptions = $custom_table->getColumnsSelectOptions([
                'append_table' => true,
                'include_parent' => true,
                'include_workflow' => true,
            ]);

            $field = $form->select('view_column_target', exmtrans("custom_view.view_column_target"))->required()
                ->options($targetOptions);

            if (boolval(config('exment.form_column_option_group', false))) {
                $targetGroups = static::convertGroups($targetOptions, $custom_table);
                $field->groups($targetGroups);
            }

            $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
            $child_tables = $relations->mapWithKeys(function($relation) {
                return [$relation->child_custom_table_id => $relation->child_custom_table->table_view_name];
            })->toArray();

            $form->text('view_column_name', exmtrans("custom_view.view_column_name"));
            $form->select('child_table_id', exmtrans("custom_view.child_table_id"))
                ->help(exmtrans("custom_view.help.child_table_id"))
                ->options($child_tables);
            $form->hidden('order')->default(0);
        })->required()->setTableColumnWidth(5, 3, 2, 2)
        ->rowUpDown('order', 10)
        ->descriptionHtml(exmtrans("custom_view.description_custom_view_columns"));

        static::setFilterFields($form, $custom_table);
        static::setSortFields($form, $custom_table);

    }
}

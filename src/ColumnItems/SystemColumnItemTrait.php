<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\CustomRelation;

trait SystemColumnItemTrait
{
    /**
     * Get relation.
     *
     * @return CustomRelation|null
     */
    protected function getRelationTrait()
    {
        $view_pivot_table = array_get($this->options, 'view_pivot_table');
        $view_pivot_column = array_get($this->options, 'view_pivot_column');

        if (empty($view_pivot_table) || empty($view_pivot_column)) {
            return null;
        }
        if ($view_pivot_column != SystemColumn::PARENT_ID) {
            return null;
        }

        return CustomRelation::getRelationByParentChild($this->custom_table, $view_pivot_table);
    }

    /**
     * Get view pivot value for 1:n or n:n
     *
     * @param \Exceedone\Exment\Model\CustomValue $custom_value
     * @param array $options
     * @return mixed
     */
    protected function getViewPivotValue($custom_value, $options)
    {
        $view_pivot_column = array_get($options, 'view_pivot_column');
        $valuekey = $this instanceof \Exceedone\Exment\ColumnItems\SystemItem ? $this->name() : 'value.'.$this->name();

        $pivot_custom_value = $this->getViewPivotCustomValue($custom_value, $options);

        if (is_list($pivot_custom_value)) {
            return collect($pivot_custom_value)->map(function ($v) use ($valuekey) {
                return array_get($v, $valuekey);
            });
        }
        return array_get($pivot_custom_value, $valuekey);
    }


    /**
     * Get view pivot custom value for 1:n or n:n
     *
     * @param \Exceedone\Exment\Model\CustomValue $custom_value
     * @param array $options
     * @return mixed
     */
    protected function getViewPivotCustomValue($custom_value, $options)
    {
        $view_pivot_column = array_get($options, 'view_pivot_column');

        $valuekey = $this instanceof \Exceedone\Exment\ColumnItems\SystemItem ? $this->name() : 'value.'.$this->name();
        // for relation ----------------------------------------------------
        if ($view_pivot_column == SystemColumn::PARENT_ID) {
            $relation = CustomRelation::getRelationByParentChild($this->custom_table, array_get($options, 'view_pivot_table'));
            if (!isset($relation)) {
                return null;
            }

            $relation_name = $relation->getRelationName();
            $relation_custom_value = $custom_value->{$relation_name};

            if (is_list($relation_custom_value)) {
                return collect($relation_custom_value);
            }

            return $relation_custom_value;
        // for select table ----------------------------------------------------
        } else {
            $pivot_custom_column = CustomColumn::getEloquent($view_pivot_column);
            $pivot_id =  array_get($custom_value, 'value.'.$pivot_custom_column->column_name);

            if (is_list($pivot_id)) {
                return collect($pivot_id)->map(function ($v) {
                    $custom_value = $this->custom_table->getValueModel($v);
                    return $custom_value;
                });
            } else {
                $custom_value = $this->custom_table->getValueModel($pivot_id);
                return $custom_value;
            }
        }
    }
}

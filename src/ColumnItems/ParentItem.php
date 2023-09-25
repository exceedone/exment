<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Encore\Admin\Form\Field\MultipleSelect;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\RelationType;

class ParentItem implements ItemInterface
{
    use ItemTrait;

    /**
     * this column's parent table
     */
    protected $parent_table;

    /**
     * this custom relation
     */
    protected $custom_relation;

    /**
     * specifying the parent table
     */
    protected $target_parent = false;

    public function __construct($custom_table, $custom_value, $parent_table = null, ?CustomRelation $custom_relation = null)
    {
        $this->custom_table = $custom_table;
        $this->value = $this->getTargetValue($custom_value);

        if (!$custom_relation) {
            $custom_relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_table->id);

            if (isset($parent_table)) {
                $custom_relation = $custom_relation->where('parent_custom_table_id', $parent_table->id);
                $this->target_parent = true;
            }
            $custom_relation = $custom_relation->first();
        }

        if (isset($custom_relation)) {
            $this->custom_relation = $custom_relation;
            $this->parent_table = $custom_relation->parent_custom_table;
        }

        $this->label = isset($this->parent_table) ? $this->parent_table->table_view_name : null;
    }

    /**
     * get column name
     */
    public function name()
    {
        if (array_get($this->options, 'grid_column')) {
            return 'parent_id';
        } elseif ($this->target_parent) {
            return 'parent_id_'.$this->parent_table->table_name.'_'.$this->custom_table->table_name;
        } else {
            return 'parent_id_'.$this->custom_table->table_name;
        }
    }

    /**
     * get column name
     */
    public function sqlname()
    {
        return 'parent_id';
    }

    /**
     * get parent_type column name
     */
    public function sqltypename()
    {
        return $this->sqlUniqueTableName() .'.parent_type';
    }

    /**
     * get target table real db name.
     */
    public function sqlRealTableName()
    {
        if ($this->custom_relation->relation_type == RelationType::ONE_TO_MANY) {
            return getDBTableName($this->custom_table);
        }

        return $this->custom_relation->getRelationName();
    }

    /**
     * get index name
     */
    public function index()
    {
        return $this->name();
    }

    /**
     * get text(for display)
     */
    protected function _text($v)
    {
        return isset($v) ? $v->getLabel() : null;
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    protected function _html($v)
    {
        if (!isset($v)) {
            return null;
        // get text column
        } elseif ($this->isPublicForm()) {
            return $v->getLabel();
        } else {
            return $v->getUrl(true);
        }
    }

    /**
     * get grid style
     */
    public function gridStyle()
    {
        return $this->getStyleString([
            'min-width' => config('exment.grid_min_width', 100) . 'px',
            'max-width' => config('exment.grid_max_width', 100) . 'px',
        ]);
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return $this->custom_relation && $this->custom_relation->relation_type == RelationType::ONE_TO_MANY;
    }

    public function setCustomValue($custom_value)
    {
        $this->value = $this->getTargetValue($custom_value);
        if (isset($custom_value)) {
            $this->id = array_get($custom_value, 'id');
            ;
        }
        $this->prepare();

        return $this;
    }

    public function getCustomTable()
    {
        return $this->custom_table;
    }

    /**
     * Get relation.
     *
     * @return CustomRelation|null
     */
    public function getRelation()
    {
        return $this->custom_relation;
    }

    protected function getTargetValue($custom_value)
    {
        if (is_null($custom_value)) {
            return;
        }
        if (is_null($this->custom_relation)) {
            return;
        }

        // if options has "summary" (for summary view)
        if (boolval(array_get($this->options, 'summary'))) {
            return $this->custom_relation->parent_custom_table_cache->getValueModel(array_get($custom_value, $this->sqlAsName()));
        }

        $relation_name = $this->custom_relation->getRelationName();
        return $custom_value->{$relation_name};
    }

    /**
     * replace value for import
     *
     * @param $value
     * @param array $setting
     * @return array
     */
    public function getImportValue($value, $setting = [])
    {
        $result = true;

        if (!isset($this->custom_table)) {
            $result = false;
        } elseif (is_null($target_column_name = array_get($setting, 'target_column_name'))) {
        } else {
            // get target value
            $target_value = $this->custom_table->getValueModel()->where("value->$target_column_name", $value)->first();

            if (!isset($target_value)) {
                $result = false;
            } else {
                $value = $target_value->id;
            }
        }

        return [
            'result' => $result,
            'value' => $value,
        ];
    }


    public function getFilterField()
    {
        if ($this->parent_table) {
            $field = new MultipleSelect($this->name(), [$this->parent_table->table_view_name]);
            $field->options(function ($value) {
                // get DB option value
                return $this->parent_table->getSelectOptions([
                    'selected_value' => $value,
                    'showMessage_ifDeny' => true,
                ]);
            });
            return $field;
        }
    }

    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        return FilterType::SELECT;
    }

    public static function getItem(...$args)
    {
        list($custom_table, $custom_value, $parent_table) = $args + [null, null, null];
        return new self($custom_table, $custom_value);
    }

    public static function getItemWithParent(...$args)
    {
        list($custom_table, $parent_table) = $args + [null, null];
        return new self($custom_table, null, $parent_table);
    }

    public static function getItemWithRelation(...$args)
    {
        list($custom_table, $custom_relation) = $args + [null, null];
        return new self($custom_table, null, null, $custom_relation);
    }

    /**
     * get sqlname for summary
     * *Please override if use.
     * Join table: true
     * Wrap: true
     *
     * @return string Ex: COUNT(`exm__3914ac5180d7dc43fcbb AS AAAA`)
     */
    public function getSummaryWrapTableColumn(): string
    {
        return '';
    }



    /**
     * Get sqlname for group by
     * Join table: true
     * Wrap: true
     *
     * @param boolean $asSelect if true, get sqlname for select column
     * @param boolean $asSqlAsName if true, get sqlname as name.
     * @return string group by column name
     */
    public function getGroupByWrapTableColumn(bool $asSelect = false, bool $asSqlAsName = false): string
    {
        $table_column_name = $asSqlAsName ? $this->getTableColumn($this->sqlAsName()) : $this->getTableColumn();

        $group_condition = array_get($this->options, 'group_condition');

        if (isset($group_condition)) {
            $result = \DB::getQueryGrammar()->getDateFormatString($group_condition, $table_column_name, !$asSelect);
        }
        // if sql server and created_at, set datetime cast
        else {
            $result = \Exment::wrapColumn($table_column_name);
        }

        return $result;
    }


    /**
     * Set where query for grid filter. If class is "ExmWhere".
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQuery($query, $input)
    {
        $relation = $this->custom_relation;
        if ($relation->relation_type == RelationType::ONE_TO_MANY) {
            RelationTable::setQueryOneMany($query, $relation->parent_custom_table, $relation->child_custom_table, $input);
        } else {
            RelationTable::setQueryManyMany($query, $relation->parent_custom_table, $relation->child_custom_table, $input);
        }
    }

    /**
     * Set admin filter options
     *
     * @param $filter
     * @return void
     */
    protected function setAdminFilterOptions(&$filter)
    {
        $relation = $this->custom_relation;
        $parent_custom_table = $relation->parent_custom_table;

        // get options and ajax url
        $options = $parent_custom_table->getSelectOptions();
        $ajax = $parent_custom_table->getOptionAjaxUrl();
        $table_view_name = $parent_custom_table->table_view_name;

        // set relation
        if (isset($ajax)) {
            $filter->multipleSelect([])->ajax($ajax, 'id', 'text');
        } else {
            $filter->multipleSelect($options);
        }
    }
}

<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\FilterType;

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
    
    public function __construct($custom_table, $custom_value, $parent_table = null)
    {
        $this->custom_table = $custom_table;
        $this->value = $this->getTargetValue($custom_value);

        $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_table->id);
        
        if (isset($parent_table)) {
            $relation = $relation->where('parent_custom_table_id', $parent_table->id);
            $this->target_parent = true;
        }
        $relation = $relation->first();

        if (isset($relation)) {
            $this->custom_relation = $relation;
            $this->parent_table = $relation->parent_custom_table;
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
        return getDBTableName($this->custom_table) .'.'. 'parent_id';
    }

    /**
     * get column name
     */
    public function sqlAsName()
    {
        return $this->sqlname();
    }

    /**
     * get parent_type column name
     */
    public function sqltypename()
    {
        return getDBTableName($this->custom_table) .'.'. 'parent_type';
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
        return true;
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

        $relation_name = $this->custom_relation->getRelationName();
        return $custom_value->{$relation_name};
    }
    
    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
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
            $field = new Select($this->name(), [$this->parent_table->table_view_name]);
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
        return FilterType::DEFAULT;
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
}

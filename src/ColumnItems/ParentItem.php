<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Select;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ViewColumnFilterType;

class ParentItem implements ItemInterface
{
    use ItemTrait;
    
    /**
     * this column's target custom_table
     */
    protected $custom_table;

    /**
     * this column's parent table
     */
    protected $parent_table;
    
    public function __construct($custom_table, $custom_value)
    {
        $this->custom_table = $custom_table;
        $this->value = $this->getTargetValue($custom_value);

        $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_table->id)->first();
        if (isset($relation)) {
            $this->parent_table = $relation->parent_custom_table;
        }

        $this->label = isset($this->parent_table) ? $this->parent_table->table_view_name : null;
    }

    /**
     * get column name
     */
    public function name()
    {
        return 'parent_id_'.$this->custom_table->table_name;
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
    public function text()
    {
        return isset($this->value) ? $this->value->getLabel() : null;
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    public function html()
    {
        return isset($this->value) ? $this->value->getUrl(true) : null;
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

    protected function getTargetValue($custom_value)
    {
        if (is_null($custom_value)) {
            return;
        }

        if (!isset($custom_value->parent_id) || !isset($custom_value->parent_type)) {
            return;
        }

        return getModelName($custom_value->parent_type)::find($custom_value->parent_id);
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
        if (!isset($this->custom_table)) {
            return null;
        }

        if (is_null($target_column_name = array_get($setting, 'target_column_name'))) {
            return $value;
        }

        // get target value
        $target_value = $this->custom_table->getValueModel()->where("value->$target_column_name", $value)->first();

        if (!isset($target_value)) {
            return null;
        }

        return $target_value->id;
    }

    
    public function getFilterField()
    {
        if ($this->parent_table) {
            $field = new Select($this->name(), [$this->parent_table->table_view_name]);
            $field->options(function ($value) {
                // get DB option value
                return $this->parent_table->getOptions($value, null, false, true);
            });
            return $field;
        }
    }
    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        return ViewColumnFilterType::DEFAULT;
    }

    public static function getItem(...$args)
    {
        list($custom_table, $custom_value) = $args + [null, null];
        return new self($custom_table, $custom_value);
    }
}

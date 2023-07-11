<?php

namespace Exceedone\Exment\ColumnItems;

use Encore\Admin\Form\Field\Date;
use Encore\Admin\Form\Field\MultipleSelect;
use Encore\Admin\Form\Field\Text;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Grid\Filter as ExmFilter;
use Exceedone\Exment\Grid\Filter\Where as ExmWhere;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FilterType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;

class SystemItem implements ItemInterface
{
    use ItemTrait{
        ItemTrait::getAdminFilterWhereQuery as getAdminFilterWhereQueryTrait;
    }
    use SystemColumnItemTrait;
    use SummaryItemTrait;
    use ColumnOptionQueryTrait;

    protected $column_name;

    protected $custom_value;

    public function __construct($custom_table, $table_column_name, $custom_value)
    {
        // if view_pivot(like select table), custom_table is target's table
        $this->custom_table = $custom_table;
        $this->setCustomValue($custom_value);

        $params = static::getOptionParams($table_column_name, $custom_table);
        $this->column_name = $params['column_target'];

        $this->setDefaultLabel($params);
    }

    /**
     * get column name
     */
    public function name()
    {
        return $this->column_name;
    }

    /**
     * get column key sql name.
     */
    public function sqlname()
    {
        return $this->getSqlColumnName(false);
    }

    /**
     * get sort name
     */
    public function getSortName()
    {
        return $this->getSqlColumnName(true);
    }

    /**
     * Get API column name
     *
     * @return string
     */
    public function apiName()
    {
        return $this->_apiName();
    }

    /**
     * get sqlname for summary
     */
    public function getSummaryWrapTableColumn(): string
    {
        $table_column_name = $this->getSqlColumnName(true);

        $summary_condition = $this->getSummaryConditionName();
        $group_condition = array_get($this->options, 'group_condition');

        if (isset($summary_condition)) {
            $table_column_name = \Exment::wrapColumn($table_column_name);
            $result = "$summary_condition($table_column_name)";
        } elseif (isset($group_condition)) {
            $result = \DB::getQueryGrammar()->getDateFormatString($group_condition, $table_column_name, false);
        }
        // if sql server and created_at, set datetime cast
        elseif (\Exment::isSqlServer() && array_get($this->getSystemColumnOption(), 'type') == 'datetime') {
            $result = \DB::getQueryGrammar()->getDateFormatString(GroupCondition::YMDHIS, $table_column_name, true);
        } else {
            $result = \Exment::wrapColumn($table_column_name);
        }

        return $result;
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
        $table_column_name = $asSqlAsName ? $this->getTableColumn($this->sqlAsName()) : $this->getSqlColumnName(true);

        $group_condition = array_get($this->options, 'group_condition');

        if (isset($group_condition)) {
            $result = \DB::getQueryGrammar()->getDateFormatString($group_condition, $table_column_name, !$asSelect);
        }
        // if sql server and created_at, set datetime cast
        elseif (\Exment::isSqlServer() && array_get($this->getSystemColumnOption(), 'type') == 'datetime') {
            $result = \DB::getQueryGrammar()->getDateFormatString(GroupCondition::YMDHIS, $table_column_name, !$asSelect);
        } else {
            $result = \Exment::wrapColumn($table_column_name);
        }

        return $result;
    }

    /**
     * get sql query column name
     *
     * @param boolean $appendTable if true, append column name
     * @return string
     */
    protected function getSqlColumnName(bool $appendTable)
    {
        // get SystemColumn enum
        $option = $this->getSystemColumnOption();
        if (!isset($option)) {
            $sqlname = $this->column_name;
        } else {
            $sqlname = array_get($option, 'sqlname');
        }

        if ($appendTable) {
            return $this->sqlUniqueTableName() .'.'. $sqlname;
        }
        return $sqlname;
    }

    /**
     * get index name
     */
    public function index()
    {
        $option = $this->getSystemColumnOption();
        //return getDBTableName($this->custom_table) .'-'. array_get($option, 'sqlname', $this->name());
        return array_get($option, 'sqlname', $this->name());
    }

    /**
     * get pure value. (In database value)
     */
    protected function _pureValue($v)
    {
        // convert to string if datetime
        $option = $this->getSystemColumnOption();
        if (array_get($option, 'type') == 'datetime') {
            if ($v instanceof \Carbon\Carbon) {
                return $v->__toString();
            }
        }
        return $v;
    }

    /**
     * get pure value. (In database value)
     */
    protected function _value($v)
    {
        return $v;
    }

    /**
     * get text(for display)
     */
    protected function _text($v)
    {
        return $this->_pureValue($v);
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    protected function _html($v)
    {
        $option = $this->getSystemColumnOption();
        if (!is_null($keyname = array_get($option, 'tagname'))) {
            // not escape because return html
            return array_get($this->custom_value, $keyname);
        }
        return esc_html($this->_text($v));
    }

    /**
     * get grid style
     */
    public function gridStyle()
    {
        $option = $this->getSystemColumnOption();
        return $this->getStyleString([
            'min-width' => array_get($option, 'min_width', config('exment.grid_min_width', 100)) . 'px',
            'max-width' => array_get($option, 'max_width', config('exment.grid_max_width', 300)) . 'px',
        ]);
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        return !array_key_value_exists('view_pivot_column', $this->options);
    }

    /**
     * set item label
     */
    public function setLabel($label)
    {
        return $this->label = $label;
    }


    /**
     * set default label
     */
    protected function setDefaultLabel($params)
    {
        // get label. check not match $this->custom_table and pivot table
        if (array_key_value_exists('view_pivot_table_id', $params) && $this->custom_table->id != $params['view_pivot_table_id']) {
            if ($params['view_pivot_column_id'] == SystemColumn::PARENT_ID) {
                $this->label = static::getViewColumnLabel(exmtrans("common.$this->column_name"), $this->custom_table->table_view_name);
            } else {
                $pivot_column = CustomColumn::getEloquent($params['view_pivot_column_id'], $params['view_pivot_table_id']);
                $this->label = static::getViewColumnLabel(exmtrans("common.$this->column_name"), $pivot_column->column_view_name);
            }
        } else {
            $this->label = exmtrans("common.$this->column_name");
        }
    }

    public function setCustomValue($custom_value)
    {
        // if contains uniqueName's value in $custom_value, set $custom_value as column name.
        // For summary. When summary, not get as system column name.
        if (array_key_value_exists($this->uniqueName, $custom_value)) {
            $option = $this->getSystemColumnOption();
            $custom_value->{array_get($option, 'sqlname')} = $custom_value[$this->uniqueName];
        }

        $this->custom_value = $this->getTargetCustomValue($custom_value);
        if (isset($custom_value)) {
            $this->id = array_get($custom_value, 'id');
            $this->value = $this->getTargetValue($custom_value);
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
        return $this->getRelationTrait();
    }

    protected function getTargetValue($custom_value)
    {
        // if options has "summary" (for summary view)
        if (boolval(array_get($this->options, 'summary'))) {
            // if group condition is weekday, return weekday format
            $v = array_get($custom_value, $this->sqlAsName());
            if (array_get($this->options, 'group_condition') == 'w') {
                return $this->getWeekdayFormat($v);
            }
            return $v;
        }

        // if options has "view_pivot_column", get select_table's custom_value first
        if (isset($custom_value) && array_key_value_exists('view_pivot_column', $this->options)) {
            return $this->getViewPivotValue($custom_value, $this->options);
        }

        return array_get($custom_value, $this->column_name);
    }

    public function getAdminField($form_column = null, $column_name_prefix = null)
    {
        $field = new Field\Display($this->name(), [$this->label()]);
        $field->default($this->text());

        return $field;
    }

    public function getFilterField($value_type = null)
    {
        if (is_null($value_type)) {
            $option = $this->getSystemColumnOption();
            $value_type = array_get($option, 'type');
        }

        switch ($value_type) {
            case 'day':
            case 'datetime':
                $field = new Date($this->name(), [$this->label()]);
                $field->default($this->value);
                break;
                // Now "select" is only user
            case 'user':
            case 'select':
                $field = new MultipleSelect($this->name(), [$this->label()]);
                $field->options(function ($value) {
                    // get DB option value
                    return CustomTable::getEloquent(SystemTableName::USER)
                        ->getSelectOptions(
                            [
                                'selected_value' => $value,
                                'display_table' => SystemTableName::USER,
                            ]
                        );
                });
                $field->default($this->value);
                break;
            default:
                $field = new Text($this->name(), [$this->label()]);
                $field->default($this->value);
                break;
        }

        return $field;
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        $option = $this->getSystemColumnOption();
        $value_type = array_get($option, 'type');

        return in_array($value_type, ['day', 'datetime']);
    }

    /**
     * whether column is datetime
     *
     */
    public function isDateTime()
    {
        $option = $this->getSystemColumnOption();
        $value_type = array_get($option, 'type');

        return in_array($value_type, ['datetime']);
    }


    /**
     * get view filter type
     */
    public function getViewFilterType()
    {
        switch ($this->column_name) {
            case SystemColumn::ID:
            case SystemColumn::SUUID:
            case SystemColumn::PARENT_ID:
                return FilterType::DEFAULT;
            case SystemColumn::CREATED_AT:
            case SystemColumn::UPDATED_AT:
                return FilterType::DAY;
            case SystemColumn::CREATED_USER:
            case SystemColumn::UPDATED_USER:
                return FilterType::USER;
            case SystemColumn::WORKFLOW_STATUS:
                return FilterType::WORKFLOW;
        }
        return FilterType::DEFAULT;
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        switch ($this->column_name) {
            case SystemColumn::ID:
            case SystemColumn::SUUID:
            case SystemColumn::PARENT_ID:
                return (string)FilterOption::EQ;
            case SystemColumn::CREATED_AT:
            case SystemColumn::UPDATED_AT:
                // Use custom query. So return null.
                return null;
            case SystemColumn::WORKFLOW_STATUS:
                return (string)FilterOption::WORKFLOW_EQ_STATUS;
            case SystemColumn::WORKFLOW_WORK_USERS:
                return (string)FilterOption::WORKFLOW_EQ_WORK_USER;
        }

        return null;
    }

    protected function getAdminFilterClass()
    {
        switch ($this->column_name) {
            case SystemColumn::CREATED_AT:
            case SystemColumn::UPDATED_AT:
                return ExmFilter\BetweenDatetime::class;
        }

        return ExmWhere::class;
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
        switch ($this->column_name) {
            case SystemColumn::CREATED_AT:
            case SystemColumn::UPDATED_AT:
                $this->getAdminFilterWhereQueryDate($query, $input);
                return;
        }

        $this->getAdminFilterWhereQueryTrait($query, $input);
    }

    /**
     * Set admin filter options
     *
     * @param $filter
     * @return void
     */
    protected function setAdminFilterOptions(&$filter)
    {
        $option = $this->getSystemColumnOption();
        if (array_get($option, 'type') == 'datetime') {
            $filter->date();
        }
    }


    protected function getSystemColumnOption()
    {
        return SystemColumn::getOption(['name' => $this->column_name]);
    }


    public static function getItem(...$args)
    {
        list($custom_table, $table_column_name, $custom_value) = $args + [null, null, null];
        return new self($custom_table, $table_column_name, $custom_value);
    }
}

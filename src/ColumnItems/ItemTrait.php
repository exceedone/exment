<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Enums\FormLabelType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Grid\Filter as ExmFilter;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Encore\Admin\Show\Field as ShowField;
use Illuminate\Contracts\Database\Query\Builder;

/**
 *
 * @property CustomTable $custom_table
 * @property CustomColumn $custom_column
 * @method array getSummaryParams()
 * @method mixed getSummaryCondition()
 * @method mixed getViewPivotCustomValue($custom_value, $options)
 */
trait ItemTrait
{
    /**
     * This custom table.
     * *If view_pivot_column, custom_table is pivot target table
     *
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * this column's target custom_table
     */
    protected $value;

    protected $label;

    protected $id;

    /**
     * Custom form column options
     *
     * @var array
     */
    protected $form_column_options = [];

    /**
     * Custom form columns in same block. Use for getting other fields.
     *
     * @var array
     */
    protected $other_form_columns = [];

    /**
     * Form items option
     *
     * [
     *     'public_form': If this form is public_form, set publcform model
     *     'as_confirm' : If this form is before confirm, set true.
     * ]
     * @var array|null
     */
    protected $options = [];


    /**
     * Unique column name.
     * For use class name, laravel-admin grid (If not use this, get same field name, return wrong value.), etc.
     *
     * @var string
     */
    protected $uniqueName;

    /**
     * Unique table name.
     * For use join relation(contains select_table).
     *
     * @var string
     */
    protected $uniqueTableName;

    public function getCustomTable()
    {
        return $this->custom_table;
    }

    public function setCustomTable(CustomTable $custom_table)
    {
        $this->custom_table = $custom_table;

        return $this;
    }

    /**
     * set item label
     */
    public function setLabel($label)
    {
        return $this->label = $label;
    }

    /**
     * CustomForm
     *
     * @var CustomForm|null
     */
    protected $custom_form;

    /**
     * get value
     */
    public function value()
    {
        return $this->_getMultipleValue(function ($v) {
            return $this->_value($v);
        });
    }

    /**
     * get pure value. (In database value)
     * *Don't override this function
     */
    public function pureValue()
    {
        return $this->_getMultipleValue(function ($v) {
            return $this->_pureValue($v);
        });
    }

    /**
     * get text
     */
    public function text()
    {
        $text = $this->_getMultipleValue(function ($v) {
            return $this->_text($v);
        });

        return is_list($text) ? collect($text)->implode($this->getSeparateWord()) : $text;
    }

    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    public function html()
    {
        $html = $this->_getMultipleValue(function ($v) {
            return $this->_html($v);
        });

        return is_list($html) ? collect($html)->implode($this->getSeparateWord()) : $html;
    }

    protected function _getMultipleValue($singleValueCallback)
    {
        $isList = is_list($this->value);
        $values = $isList ? $this->value : [$this->value];

        $items = [];
        foreach ($values as $value) {
            $items[] = $singleValueCallback($value);
        }

        $items = collect($items)->filter(function ($item) {
            return !is_nullorempty($item);
        });

        if ($isList) {
            return $items;
        }

        return $items->first();
    }

    /**
     * get value
     */
    protected function _value($v)
    {
        return $v;
    }

    /**
     * get pure value. (In database value)
     * *Don't override this function
     */
    protected function _pureValue($v)
    {
        return $v;
    }

    /**
     * get or set option for convert
     */
    public function options($options = null)
    {
        if (!func_num_args()) {
            return $this->options ?? [];
        }

        $this->options = array_merge(
            $this->options ?? [],
            $options
        );

        return $this;
    }

    /**
     * get label. (user theader, form label etc...)
     */
    public function label($label = null)
    {
        if (!func_num_args()) {
            return array_get($this->form_column_options, 'form_column_view_name') ?? $this->label;
        }
        if (isset($label)) {
            $this->label = $label;
        }
        return $this;
    }

    /**
     * get value's id.
     */
    public function id($id = null)
    {
        if (!func_num_args()) {
            return $this->id;
        }
        $this->id = $id;
        return $this;
    }

    public function prepare()
    {
    }

    /**
     * whether column is enabled index.
     *
     */
    public function indexEnabled()
    {
        return true;
    }

    /**
     * get cast name for sort
     */
    public function getCastName()
    {
        return null;
    }

    /**
     * get sort name
     */
    public function getSortName()
    {
        return $this->name();
    }

    /**
     * Get API column name
     *
     * @return string
     */
    public function apiName()
    {
        return $this->name();
    }

    /**
     * Get unique name. Use for classname, column name(Only not sorted).
     *
     * @return string
     */
    public function uniqueName()
    {
        if (is_nullorempty($this->uniqueName)) {
            $this->uniqueName = make_randomstr(20, true, false);
        }
        return $this->uniqueName;
    }

    /**
     * Set unique name.
     *
     * @return $this
     */
    public function setUniqueName($uniqueName)
    {
        $this->uniqueName = $uniqueName;
        return $this;
    }

    public function sqlAsName()
    {
        return $this->uniqueName();
    }

    /**
     * get target table real db name.
     */
    public function sqlRealTableName()
    {
        return getDBTableName($this->custom_table);
    }


    /**
     * get target table unique db name.
     * Maybe, sql join same db table, so we have to set unique table name.
     */
    public function sqlUniqueTableName()
    {
        if (!is_nullorempty($this->uniqueTableName)) {
            return $this->uniqueTableName;
        }
        return $this->sqlRealTableName();
    }


    /**
     * Set unique table name, for join relation tables.
     * Maybe, sql join same db table, so we have to set unique table name.
     *
     * @param $uniqueTableName string sets unique name.
     * @return $this
     */
    public function setUniqueTableName(string $uniqueTableName)
    {
        $this->uniqueTableName = $uniqueTableName;
        return $this;
    }

    /**
     * Get target custom value.
     * Almost return args custom value, but maybe view_pivot_column, get relation parents custom value.
     *
     * @param \Exceedone\Exment\Model\CustomValue $custom_value
     * @return \Exceedone\Exment\Model\CustomValue|\Illuminate\Support\Collection|null
     */
    protected function getTargetCustomValue($custom_value)
    {
        // if summary, cannot get view_pivot_column, so return $custom_value.
        if (array_boolval($this->options, 'summary')) {
            return $custom_value;
        }

        // if options has "view_pivot_column", get select_table's custom_value first
        if (!is_nullorempty($custom_value) && array_key_value_exists('view_pivot_column', $this->options)) {
            return $this->getViewPivotCustomValue($custom_value, $this->options);
        }

        return $custom_value;
    }

    /**
     * Get API column definition
     *
     * @return array
     */
    public function apiDefinitions()
    {
        $items = [];
        $items['table_name'] = $this->custom_table->table_name;
        $items['column_name'] = $this->name();
        $items['label'] = $this->label();

        if (method_exists($this, 'getSummaryConditionName')) {
            $summary_condition = $this->getSummaryConditionName();
            if (isset($summary_condition)) {
                $items['summary_condition'] = $summary_condition;
            }
        }

        return $items;
    }


    /**
     * Get column name with table name.
     * Join table: true
     * Wrap: false
     *
     * @return string Joined DB table name and column name.  Ex. "exm__3914ac5180d7dc43fcbb.column1" or "sfhwuiefhkmklml.column1"
     */
    public function getTableColumn(?string $column_name = null): string
    {
        if (!$column_name) {
            $column_name = $this->sqlname();
        }
        return $this->sqlUniqueTableName() . ".$column_name";
    }


    /**
     * The column to use for sorting.
     * Join table: true
     * Wrap: true
     *
     * @return string
     */
    public function getSortWrapTableColumn(): string
    {
        return $this->getCastWrapTableColumn();
    }


    /**
     * The cast column.
     * Join table: true
     * Wrap: true
     * @param string|null $column_name If select column name, set.
     *
     * @return string
     */
    public function getCastWrapTableColumn(?string $column_name = null): string
    {
        return $this->getCastColumn($column_name, true, true);
    }

    /**
     * Get date format(Ex. date_format(XXXX,'%Y-%m-%d'))
     * Join table: true
     * Wrap: true
     *
     * get sqlname for summary
     */
    public function getDateFormatWrapTableColumn(string $format): string
    {
        $table_column_name = $this->getTableColumn();
        return \DB::getQueryGrammar()->getDateFormatString($format, $table_column_name);
    }

    /**
     * Get summary query,for use join sub.
     *
     * MIN, MAX : non summary.
     * COUNT, SUM : SUM.
     *
     * Join table: true
     * Wrap: true
     *
     * @return string
     */
    public function getSummaryJoinResultWrapTableColumn(): string
    {
        $options = $this->getSummaryParams();
        // get normal summary condition.
        $summary_condition = $this->getSummaryCondition();

        $new_summary_condition = null;
        switch ($summary_condition) {
            case SummaryCondition::SUM:
            case SummaryCondition::COUNT:
                $new_summary_condition = SummaryCondition::getSummaryConditionName(SummaryCondition::SUM);
                break;
            case SummaryCondition::MIN:
            case SummaryCondition::MAX:
                $new_summary_condition = SummaryCondition::getSummaryConditionName($summary_condition);
                break;
        }

        // get wraped, joined table, and sub query's as name.
        $wrapCastColumn = \Exment::wrapColumn($this->getTableColumn($this->sqlAsName()));
        if (isset($new_summary_condition)) {
            // add condition.
            $result = "$new_summary_condition($wrapCastColumn)";
        } else {
            $result = $wrapCastColumn;
        }

        return $result;
    }

    /**
     * Get group by query,for use join sub.
     *
     * MIN, MAX : group string.
     * Others : null.
     *
     * Join table: true
     * Wrap: true
     *
     * @return string|null
     */
    public function getGroupByJoinResultWrapTableColumn(): ?string
    {
        $options = $this->getSummaryParams();
        // get normal summary condition.
        $summary_condition = $this->getSummaryCondition();

        switch ($summary_condition) {
            case SummaryCondition::MAX:
            case SummaryCondition::MIN:
                // get wraped, joined table, and sub query's as name.
                return \Exment::wrapColumn($this->getTableColumn($this->sqlAsName()));
        }
        return null;
    }

    /**
     * get cast column name as SQL
     *
     * @param string|null $column_name If select column name, set.
     * @param boolean $wrap
     * @param boolean $appendDatabaseTable
     * @return string Cast column string.
     * Ex1. If use cast type: CAST(`exm__3914ac5180d7dc43fcbb.column_sfbhiuewfb` AS signed)
     * Ex2. If not use cast type: `exm__3914ac5180d7dc43fcbb.column_sfbhiuewfb`
     */
    protected function getCastColumn(?string $column_name = null, bool $wrap = true, bool $appendDatabaseTable = true): string
    {
        $cast = $this->getCastName();

        if (is_nullorempty($column_name)) {
            $column_name = $this->indexEnabled() ? $this->index() : $this->sqlname();
        }

        if ($appendDatabaseTable) {
            // append table name
            $column_name = $this->getTableColumn($column_name);
        }

        if ($wrap) {
            $column_name = \Exment::wrapColumn($column_name);
        }

        if (!isset($cast)) {
            return $column_name;
        }

        return "CAST($column_name AS $cast)";
    }

    /**
     * get style string from key-values
     *
     * @param array $array
     * @return string
     */
    public function getStyleString(array $array = [])
    {
        $array['word-wrap'] = 'break-word';
        $array['white-space'] = 'normal';
        return implode('; ', collect($array)->map(function ($value, $key) {
            return "$key:$value";
        })->toArray());
    }


    protected function getLabelType(): string
    {
        $field_label_type = array_get($this->form_column_options, 'field_label_type') ?? FormLabelType::FORM_DEFAULT;

        // get form info
        if ($field_label_type == FormLabelType::FORM_DEFAULT && isset($this->custom_form)) {
            $field_label_type = $this->custom_form->getOption('form_label_type') ?? FormLabelType::HORIZONTAL;
        }

        return $field_label_type;
    }

    /**
     * Set show field options
     *
     * @param ShowField $field
     * @param array $options
     * @return void
     */
    public function setShowFieldOptions(ShowField $field, array $options = [])
    {
        $options = array_merge([
            'gridShows' => false,
        ], $options);

        $item = $this;
        $field->as(function ($v) use ($item) {
            /** @phpstan-ignore-next-line Call to function is_null() with $this(Exceedone\Exment\ColumnItems\SystemItem) will always evaluate to false. */
            if (is_null($this)) {
                return '';
            }
            return $item->setCustomValue($this)->html();
        })->setEscape(false);

        // If grid shows, set label style
        if ($options['gridShows'] && method_exists($this, 'setLabelType')) {
            $this->setLabelType($field);
        }
    }

    /**
     * Set custom form column options
     *
     * @param  array|CustomFormColumn|null  $form_column_options  Custom form column options
     *
     * @return  self|void
     */
    public function setFormColumnOptions($form_column_options)
    {
        if (is_null($form_column_options)) {
            return;
        }
        if ($form_column_options instanceof CustomFormColumn) {
            $form_column_options = $form_column_options->options;
        }
        $this->form_column_options = $form_column_options;

        return $this;
    }

    /**
     * Set other_form_columns
     *
     * @param  array|null  $other_form_columns Other form columns
     *
     * @return  self|void
     */
    public function setOtherFormColumns($other_form_columns)
    {
        if (is_null($other_form_columns)) {
            return;
        }
        $this->other_form_columns = $other_form_columns;

        return $this;
    }

    /**
     * Get relation.
     *
     * @return CustomRelation|null
     */
    public function getRelation()
    {
        return null;
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return false;
    }

    /**
     * whether column is datetime
     *
     */
    public function isDateTime()
    {
        return false;
    }

    /**
     * whether column is Numeric
     *
     */
    public function isNumeric()
    {
        return false;
    }

    public function isMultipleEnabled()
    {
        return false;
    }


    /**
     * Whether this form is public form.
     *
     * @return boolean
     */
    public function isPublicForm(): bool
    {
        return !is_nullorempty(array_get($this->options, 'public_form'));
    }

    public function readonly()
    {
        return false;
    }

    public function viewonly()
    {
        return false;
    }

    public function hidden()
    {
        return false;
    }

    public function internal()
    {
        return false;
    }

    /**
     * Hide when showing display
     *
     * @return bool
     */
    public function disableDisplayWhenShow(): bool
    {
        return false;
    }

    /**
     * Get Search queries for free text search
     *
     * @param string $mark
     * @param string $value
     * @param int $takeCount
     * @param string|null $q
     * @return array
     */
    public function getSearchQueries($mark, $value, $takeCount, $q, $options = [])
    {
        list($mark, $pureValue) = $this->getQueryMarkAndValue($mark, $value, $q, $options);

        $query = $this->custom_table->getValueQuery();

        $query->whereOrIn($this->custom_column->getIndexColumnName(), $mark, $pureValue)->select('id');

        $query->take($takeCount);

        return [$query];
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $query
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return $this
     */
    public function setSearchOrWhere(&$query, $mark, $value, $q)
    {
        list($mark, $pureValue) = $this->getQueryMarkAndValue($mark, $value, $q);

        if (is_list($pureValue)) {
            $query->orWhereIn($this->custom_column->getIndexColumnName(), toArray($pureValue));
        } else {
            $query->orWhere($this->custom_column->getIndexColumnName(), $mark, $pureValue);
        }

        return $this;
    }

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param string $label
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label)
    {
        return null;
    }

    protected function getQueryMarkAndValue($mark, $value, $q, $options = [])
    {
        $options = array_merge([
            'relation' => false,
        ], $options);

        if (is_nullorempty($q)) {
            return [$mark, $value];
        }

        // if not relation search, get pure value
        if (!boolval($options['relation'])) {
            $pureValue = $this->getPureValue($q);
        } else {
            $pureValue = $value;
        }

        if (is_null($pureValue)) {
            return [$mark, $value];
        }

        return ['=', $pureValue];
    }


    /**
     * Convert filter value.
     * Ex. If value is decimal and Column Type is decimal, return floatval.
     *
     * @param mixed $value
     * @return mixed
     */
    public function convertFilterValue($value)
    {
        return $value;
    }


    protected function getAdminFilterClass()
    {
        return ExmFilter\Where::class;
    }

    /**
     * set admin filter for filtering grid.
     */
    public function setAdminFilter(&$filter)
    {
        $classname = $this->getAdminFilterClass();

        // if where query, call Cloquire
        if (in_array($classname, [ExmFilter\Where::class, ExmFilter\Between::class, ExmFilter\BetweenDate::class, ExmFilter\BetweenDatetime::class])) {
            $item = $this;
            $filteritem = new $classname(function ($query, $input) use ($item) {
                $item->getAdminFilterWhereQuery($query, $input);
            }, $this->label(), !is_nullorempty($this->uniqueName) ? $this->uniqueName : $this->index());
        } else {
            // Refactor, so doesn't use not ExmWhere class
            throw new \Exception('Please use ExmWhere');
        }

        if ($this->isShowFilterNullCheck()) {
            $filteritem->showNullCheck();
        }

        // set whereNull query
        $filteritem->whereNull(function ($query) {
            $this->getAdminFilterWhereNullQuery($query);
        });

        // first, set $filter->use
        $filter->use($filteritem);

        // next, set admin filter options
        $this->setAdminFilterOptions($filteritem);
    }

    /**
     * Set admin filter options
     *
     * @param $filter
     * @return void
     */
    protected function setAdminFilterOptions(&$filter)
    {
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::EQ;
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
        // get vieww filter item
        $viewFilterItem = ViewFilterBase::make($this->getGridFilterOption(), $this);
        $viewFilterItem->setFilter($query, $input);
    }

    /**
     * Set where null query for grid filter. If class is "ExmWhere".
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @return void
     */
    public function getAdminFilterWhereNullQuery($query)
    {
        // get vieww filter item
        $viewFilterItem = ViewFilterBase::make(FilterOption::NULL, $this);
        $viewFilterItem->setFilter($query, null);
    }

    /**
     * Set where query for grid filter for number
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQueryNumber($query, $input)
    {
        if (array_key_value_exists('start', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::NUMBER_GTE, $this);
            $viewFilterItem->setFilter($query, $input['start']);
        }
        if (array_key_value_exists('end', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::NUMBER_LTE, $this);
            $viewFilterItem->setFilter($query, $input['end']);
        }
    }
    /**
     * Set where query for grid filter for date, datetime
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQueryDate($query, $input)
    {
        if (array_key_value_exists('start', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::DAY_ON_OR_AFTER, $this);
            $viewFilterItem->setFilter($query, $input['start']);
        }
        if (array_key_value_exists('end', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::DAY_ON_OR_BEFORE, $this);
            $viewFilterItem->setFilter($query, $input['end']);
        }
    }

    /**
     * Get weekday format
     *
     * @return string|null
     */
    protected function getWeekdayFormat($val)
    {
        if (is_null($val)) return null;

        // get weekday and no list
        $weekdayNos = $this->getWeekdayNolist();

        return exmtrans('common.weekday.' . array_get($weekdayNos, $val));
    }

    protected function getWeekdayNolist()
    {
        return [
            '0' => 'sun',
            '1' => 'mon',
            '2' => 'tue',
            '3' => 'wed',
            '4' => 'thu',
            '5' => 'fri',
            '6' => 'sat',
        ];
    }

    /**
     * Whether is show filter null check
     *
     * @return bool
     */
    public function isShowFilterNullCheck(): bool
    {
        return false;
    }




    /**
     * Set customForm
     *
     * @param  CustomForm  $custom_form  CustomForm
     *
     * @return  self
     */
    public function setCustomForm(CustomForm $custom_form)
    {
        $this->custom_form = $custom_form;

        return $this;
    }

    /**
     * Whether the table in this column is different from the column in the form
     *
     * @return bool
     */
    public function isDefferentFormTable(): bool
    {
        if (!$this->custom_form) {
            return false;
        }

        return !isMatchString($this->custom_form->custom_table_cache->id, $this->custom_table->id);
    }

    /**
     * Get separate word for multiple
     *
     * @return string|null
     */
    protected function getSeparateWord(): ?string
    {
        return exmtrans('common.separate_word');
    }


    /**
     * Get font awesome class
     *
     * @return string|null
     */
    public function getFontAwesomeClass(): ?string
    {
        return null;
    }

    /**
     * get grid header style
     */
    public function gridHeaderStyle()
    {
        return [];
    }
}

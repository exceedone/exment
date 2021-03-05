<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomForm;
use Encore\Admin\Show\Field as ShowField;

/**
 *
 * @property CustomTable $custom_table
 * @property CustomColumn $custom_column
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
     * Form items option
     * 
     * [
     *     'public_form': If this form is public_form, set publcform model
     *     'as_confirm' : If this form is before confirm, set true.
     * ]
     * @var array
     */
    protected $options = [];

    protected $uniqueName;

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
     * CustomForm
     *
     * @var CustomForm
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
        
        $items = collect($items)->filter(function($item){ return !is_nullorempty($item); });
 
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
            return $this->label;
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
            $this->uniqueName = make_uuid();
        }
        return $this->uniqueName;
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
     * get sort column name as SQL
     */
    public function getSortColumn()
    {
        return $this->getCastColumn();
    }

    /**
     * get cast column name as SQL
     */
    public function getCastColumn($column_name = null, bool $wrap = true)
    {
        $cast = $this->getCastName();

        if (is_nullorempty($column_name)) {
            $column_name = $this->indexEnabled() ? $this->index() : $this->sqlname();
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

    /**
     * Set show field options
     *
     * @param mixed $field
     * @return void
     */
    public function setShowFieldOptions(ShowField $field, array $options = [])
    {
        $options = array_merge([
            'gridShows' => false,
        ], $options);

        $item = $this;
        $field->as(function ($v) use ($item) {
            if (is_null($this)) {
                return '';
            }
            return $item->setCustomValue($this)->html();
        })->setEscape(false);

        // If grid shows, set label style
        if($options['gridShows'] && method_exists($this, 'setLabelType')){
            $this->setLabelType($field);
        }
    }

    /**
     * Set custom form column options
     *
     * @param  array  $form_column_options  Custom form column options
     *
     * @return  self
     */ 
    public function setFormColumnOptions(?array $form_column_options)
    {
        $this->form_column_options = $form_column_options;

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
    public function isPublicForm() : bool
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
    public function disableDisplayWhenShow() : bool
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

        $query = $this->custom_table->getValueModel()->query();
        
        $query->whereOrIn($this->custom_column->getIndexColumnName(), $mark, $pureValue)->select('id');
        
        $query->take($takeCount);

        return [$query];
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $mark
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return void
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
    public function isDefferentFormTable() : bool
    {
        if(!$this->custom_form){
            return false;
        }

        return !isMatchString($this->custom_form->custom_table_cache->id, $this->custom_table->id);
    }

    /**
     * Get separate word for multiple
     *
     * @return string|null
     */
    protected function getSeparateWord() : ?string
    {
        return exmtrans('common.separate_word');
    }


    /**
     * Get font awesome class
     *
     * @return string|null
     */
    public function getFontAwesomeClass() : ?string{
        return null;
    }
}

<?php

namespace Exceedone\Exment\ColumnItems;

trait ItemTrait
{
    /**
     * this column's target custom_table
     */
    protected $value;

    protected $label;

    protected $id;

    protected $options;

    /**
     * get value
     */
    public function value()
    {
        return $this->value;
    }

    /**
     * get pure value. (In database value)
     * *Don't override this function
     */
    public function pureValue()
    {
        return $this->value;
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
     * Get API column name
     *
     * @return string
     */
    public function apiName()
    {
        return $this->name();
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
    public function getCastColumn($column_name = null)
    {
        $cast = $this->getCastName();

        if (is_nullorempty($column_name)) {
            $column_name = $this->indexEnabled() ? $this->index() : $this->sqlname();
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
     * whether column is date
     *
     */
    public function isDate()
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
}

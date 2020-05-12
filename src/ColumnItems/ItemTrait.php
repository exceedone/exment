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
     * get sort column name as SQL
     */
    public function getSortColumn()
    {
        $cast = $this->getCastName();
        $index = $this->index();
        
        if (!isset($cast)) {
            return $index;
        }

        return "CAST($index AS $cast)";
    }

    /**
     * get style string from key-values
     *
     * @param [type] $array
     * @return void
     */
    public function getStyleString($array)
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
     * @param [type] $mark
     * @param [type] $value
     * @param [type] $takeCount
     * @return void
     */
    public function getSearchQueries($mark, $value, $takeCount, $q)
    {
        list($mark, $pureValue) = $this->getQueryMarkAndValue($mark, $value, $q);

        $query = $this->custom_table->getValueModel()->query();
        
        if (is_list($pureValue)) {
            $query->whereIn($this->custom_column->getIndexColumnName(), toArray($pureValue))->select('id');
        } else {
            $query->where($this->custom_column->getIndexColumnName(), $mark, $pureValue)->select('id');
        }
        
        $query->take($takeCount);

        return [$query];
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param [type] $mark
     * @param [type] $value
     * @param [type] $takeCount
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
     * @param [type] $value
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label)
    {
        return null;
    }

    protected function getQueryMarkAndValue($mark, $value, $q)
    {
        if (is_nullorempty($q)) {
            return [$mark, $value];
        }

        $pureValue = $this->getPureValue($q);
        if (is_null($pureValue)) {
            return [$mark, $value];
        }

        return ['=', $pureValue];
    }
}

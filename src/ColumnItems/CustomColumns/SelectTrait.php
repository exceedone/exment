<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait SelectTrait
{
    public function getSelectFilterQuery($query, $input)
    {
        return $query->whereInArrayString($this->index(), $input);
    }

    public function isMultipleEnabledTrait()
    {
        return boolval(array_get($this->custom_column, 'options.multiple_enabled', false));
    }

    /**
     * Get default type and value
     *
     * @return array offset 0: type, 1: value
     */
    protected function getDefaultSetting()
    {
        list($default_type, $default) = parent::getDefaultSetting();

        if ($this->isMultipleEnabled() && is_string($default)) {
            $default = explode(',', $default);
        }

        return [$default_type, $default];
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $mark
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return $this
     */
    public function setSearchOrWhere(&$query, $mark, $value, $q)
    {
        return $this->_setSearchOrWhere($query, $mark, $value, $q);
    }

    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $mark
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return $this
     */
    protected function _setSearchOrWhere(&$query, $mark, $value, $q)
    {
        if ($this->isMultipleEnabled()) {
            $pureValue = $this->getPureValue($q)?? $q;
            $isUseUnicode = \ExmentDB::isUseUnicodeMultipleColumn();
            $query_value = collect($pureValue)->map(function ($val) use ($isUseUnicode) {
                return $isUseUnicode? unicode_encode($val): $val;
            })->toArray();
            $query->orWhereInArrayString($this->custom_column->getIndexColumnName(), $query_value);
            return $this;
        }
        return parent::setSearchOrWhere($query, $mark, $value, $q);
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
    protected function getSearchQueriesTrait($mark, $value, $takeCount, $q, $options = [])
    {
        if ($this->isMultipleEnabled()) {
            list($mark, $pureValue) = $this->getQueryMarkAndValue($mark, $value, $q, $options);
            $isUseUnicode = \ExmentDB::isUseUnicodeMultipleColumn();
            $query = $this->custom_table->getValueQuery();
            $query_value = collect($pureValue)->map(function ($val) use ($isUseUnicode) {
                $val = trim($val, '%');
                return $isUseUnicode? unicode_encode($val): $val;
            })->toArray();
            $query->orWhereInArrayString($this->custom_column->getIndexColumnName(), $query_value)->select('id');
            $query->take($takeCount);
            return [$query];
        }
        return parent::getSearchQueries($mark, $value, $takeCount, $q, $options);
    }
}

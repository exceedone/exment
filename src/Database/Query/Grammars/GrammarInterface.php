<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Exceedone\Exment\Enums\GroupCondition;

interface GrammarInterface
{
    /**
     * Whether support wherein multiple column.
     *
     * @return bool
     */
    public function isSupportWhereInMultiple(): bool;

    /**
     * wherein string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @param string $tableName database table name
     * @param string $column target table name
     * @param array $values
     * @param bool $isOr
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInArrayString($builder, string $tableName, string $column, $values, bool $isOr = false, bool $isNot = false);

    /**
     * wherein column.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @param string $tableName database table name
     * @param string $column target table name
     * @param string $baseColumn join base column
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInArrayColumn($builder, string $tableName, string $baseColumn, string $column, bool $isOr = false, bool $isNot = false);

    public function wrapWhereInMultiple(array $columns);

    /**
     * Bind and flatten value results.
     *
     * @return array offset 0: bind string for wherein (?, ?, )
     */
    public function bindValueWhereInMultiple(array $values);

    /**
     * Get cast column string
     *
     * @return string
     */
    public function getCastColumn($type, $column, $options = []);

    /**
     * Get column type string. Almost use virtual column.
     *
     * @return string
     */
    public function getColumnTypeString($type);

    /**
     * Get cast string
     *
     * @return string
     */
    public function getCastString($type, $addOption = false, $options = []);

    /**
     * Get date format string
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param string $column column name
     * @param bool $groupBy if group by query, return true
     *
     * @return string|null
     */
    public function getDateFormatString($groupCondition, $column, $groupBy = false, $wrap = true);

    /**
     * convert carbon date to date format
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param \Carbon\Carbon $carbon
     *
     * @return string
     */
    public function convertCarbonDateFormat($groupCondition, $carbon);

    /**
     * Wrap and add json_unquote if needs
     *
     * @param mixed $value
     * @param boolean $prefixAlias
     * @return string
     */
    public function wrapJsonUnquote($value, $prefixAlias = false);
}

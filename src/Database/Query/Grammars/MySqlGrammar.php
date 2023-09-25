<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\MySqlGrammar as BaseGrammar;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\GroupCondition;

class MySqlGrammar extends BaseGrammar implements GrammarInterface
{
    use GrammarTrait;


    public function compileUpdateRemovingJsonKey($query, string $key): string
    {
        $table = $this->wrapTable($query->from);

        // Creating json value

        $path = explode('->', $key);

        $field = $this->wrapValue(array_shift($path));

        $accessor = "'$.\"".implode('"."', $path)."\"'";

        $column = "{$field} = json_remove({$field}, {$accessor})";

        // If the query has any "join" clauses, we will setup the joins on the builder
        // and compile them so we can attach them to this update, as update queries
        // can get join statements to attach to other tables when they're needed.
        $joins = '';

        if (isset($query->joins)) {
            $joins = ' '.$this->compileJoins($query, $query->joins);
        }

        // Of course, update queries may also be constrained by where clauses so we'll
        // need to compile the where clauses and attach it to the query so only the
        // intended records are updated by the SQL statements we generate to run.
        $wheres = $this->compileWheres($query);

        return trim("update {$table}{$joins} set $column $wheres");
    }

    /**
     * Whether support wherein multiple column.
     *
     * @return bool
     */
    public function isSupportWhereInMultiple(): bool
    {
        return true;
    }


    /**
     * wherein string.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @param string $tableName database table name
     * @param string $column target table name
     * @param array $values
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInArrayString($builder, string $tableName, string $column, $values, bool $isOr = false, bool $isNot = false)
    {
        $index = $this->wrap($column);

        if ($isNot) {
            $queryStr = "NOT FIND_IN_SET(?, IFNULL(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''), ''))";
        } else {
            $queryStr = "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
        }

        if (is_list($values)) {
            $func = $isOr ? 'orWhere' : 'where';
            $builder->{$func}(function ($query) use ($queryStr, $values, $isNot) {
                $subfunc = $isNot ? 'whereRaw' : 'orWhereRaw';
                foreach ($values as $i) {
                    $query->{$subfunc}($queryStr, $i);
                }
            });
        } else {
            $func = $isOr ? 'orWhereRaw' : 'whereRaw';
            $builder->{$func}($queryStr, $values);
        }

        return $builder;
    }

    /**
     * wherein column.
     * Ex. column is 1,12,23,31 , and want to match 1, getting.
     *
     * @param \Illuminate\Database\Query\Builder $builder
     * @param string $tableName database table name
     * @param string $baseColumn join base column
     * @param string $column target table name
     * @return \Illuminate\Database\Query\Builder
     */
    public function whereInArrayColumn($builder, string $tableName, string $baseColumn, string $column, bool $isOr = false, bool $isNot = false)
    {
        $index = $this->wrap($column);
        $baseColumnIndex = $this->wrap($baseColumn);

        if ($isNot) {
            $queryStr = "NOT FIND_IN_SET({$baseColumnIndex}, IFNULL(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''), ''))";
        } else {
            $queryStr = "FIND_IN_SET({$baseColumnIndex}, REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
        }

        $func = $isOr ? 'orWhereRaw' : 'whereRaw';
        $builder->{$func}($queryStr);

        return $builder;
    }


    /**
     * Get cast column string
     *
     * @return string
     */
    public function getCastColumn($type, $column, $options = [])
    {
        $cast = $this->getCastString($type, $column, $options);

        $column = $this->wrap($column);

        return "CAST($column AS $cast)";
    }

    /**
     * Get column type string. Almost use virtual column.
     *
     * @return string
     */
    public function getColumnTypeString($type)
    {
        switch ($type) {
            case DatabaseDataType::TYPE_INTEGER:
                return 'bigint';
            case DatabaseDataType::TYPE_DECIMAL:
                return 'decimal';
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                return 'nvarchar(768)';
            case DatabaseDataType::TYPE_DATE:
                return 'date';
            case DatabaseDataType::TYPE_DATETIME:
                return 'datetime';
            case DatabaseDataType::TYPE_TIME:
                return 'time';
        }
        return 'nvarchar(768)';
    }

    /**
     * Get cast string
     *
     * @return string
     */
    public function getCastString($type, $addOption = false, $options = [])
    {
        $cast = '';
        switch ($type) {
            case DatabaseDataType::TYPE_INTEGER:
                $cast = 'signed';
                break;
            case DatabaseDataType::TYPE_DECIMAL:
                $cast = 'decimal';
                break;
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                $cast = 'varchar';
                break;
            case DatabaseDataType::TYPE_DATE:
                $cast = 'date';
                break;
            case DatabaseDataType::TYPE_DATETIME:
                $cast = 'datetime';
                break;
            case DatabaseDataType::TYPE_TIME:
                $cast = 'time';
                break;
        }

        if (!$addOption) {
            return $cast;
        }

        $length = array_get($options, 'length') ?? 50;

        switch ($type) {
            case DatabaseDataType::TYPE_DECIMAL:
                $decimal_digit = array_get($options, 'decimal_digit') ?? 2;
                $cast .= "($length, $decimal_digit)";
                break;

            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                $cast .= "($length)";
                break;
        }

        return $cast;
    }

    /**
     * Get date format string
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param string $column column name
     * @param bool $groupBy if group by query, return true
     *
     * @return string|null
     */
    public function getDateFormatString($groupCondition, $column, $groupBy = false, $wrap = true)
    {
        if ($wrap) {
            $column = $this->wrap($column);
        } elseif ($this->isJsonSelector($column)) {
            $column = $this->wrapJsonUnquote($column);
        }

        switch ($groupCondition) {
            case GroupCondition::Y:
                return "date_format($column, '%Y')";
            case GroupCondition::YM:
                return "date_format($column, '%Y-%m')";
            case GroupCondition::YMD:
                return "date_format($column, '%Y-%m-%d')";
            case GroupCondition::M:
                return "date_format($column, '%m')";
            case GroupCondition::D:
                return "date_format($column, '%d')";
            case GroupCondition::W:
                return "date_format($column, '%w')";
            case GroupCondition::YMDHIS:
                // not use
                return null;
        }

        return null;
    }

    /**
     * convert carbon date to date format
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param \Carbon\Carbon $carbon
     *
     * @return string|null
     */
    public function convertCarbonDateFormat($groupCondition, $carbon)
    {
        switch ($groupCondition) {
            case GroupCondition::Y:
                return $carbon->format('Y');
            case GroupCondition::YM:
                return $carbon->format('Y-m');
            case GroupCondition::YMD:
                return $carbon->format('Y-m-d');
            case GroupCondition::M:
                return $carbon->format('m');
            case GroupCondition::D:
                return $carbon->format('d');
            case GroupCondition::W:
                return $carbon->format('w');
            case GroupCondition::YMDHIS:
                return $carbon->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * Wrap and add json_unquote if needs
     *
     * @param mixed $value
     * @param boolean $prefixAlias
     * @return string
     */
    public function wrapJsonUnquote($value, $prefixAlias = false)
    {
        return "json_unquote(" . $this->wrap($value, $prefixAlias) . ")";
    }
}

<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\SqlServerGrammar as BaseGrammar;
use Illuminate\Database\Query\Builder;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\GroupCondition;

class SqlServerGrammar extends BaseGrammar implements GrammarInterface
{
    use GrammarTrait;


    /**
     * Whether support wherein multiple column.
     *
     * @return bool
     */
    public function isSupportWhereInMultiple(): bool
    {
        return false;
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
        $queryStr = "STRING_SPLIT(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\"', ''), ',')";

        // definition table name
        $tableNameAs = "{$tableName}_exists";
        $tableNameWrap = $this->wrapTable($tableName);
        $tableNameWrapAs = $this->wrapTable($tableNameAs);

        // CREATE "CROSS APPLY"
        $fromRaw = "$tableNameWrap as $tableNameWrapAs CROSS APPLY $queryStr AS CROSS_APPLY_TABLE";

        $func = $isNot ? 'whereNotExists' : 'whereExists';
        $builder->{$func}(function ($query) use ($values, $fromRaw, $tableNameWrap, $tableNameWrapAs) {
            $query->select(\DB::raw(1))
                // fromRaw is wrapped.
                ->fromRaw($fromRaw)
                // $tableNameWrapAs and $tableNameWrap is wrapped.
                ->whereRaw("$tableNameWrapAs.id = $tableNameWrap.id")
                ->whereIn("CROSS_APPLY_TABLE.value", toArray($values));
        });

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
        $queryStr = "STRING_SPLIT(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\"', ''), ',')";

        // definition table name
        $tableNameAs = "{$tableName}_exists";
        $tableNameWrap = $this->wrapTable($tableName);
        $tableNameWrapAs = $this->wrapTable($tableNameAs);

        // CREATE "CROSS APPLY"
        $fromRaw = "$tableNameWrap as $tableNameWrapAs CROSS APPLY $queryStr AS CROSS_APPLY_TABLE";

        $func = $isNot ? 'whereNotExists' : 'whereExists';
        $builder->{$func}(function ($query) use ($baseColumn, $fromRaw, $tableNameWrap, $tableNameWrapAs) {
            $query->select(\DB::raw(1))
                // fromRaw is wrapped.
                ->fromRaw($fromRaw)
                // $tableNameWrapAs and $tableNameWrap is wrapped.
                ->whereRaw("$tableNameWrapAs.id = $tableNameWrap.id")
                ->whereColumn("CROSS_APPLY_TABLE.value", $baseColumn);
        });

        return $builder;

//        TODO: unreachable statement
//        $index = $this->wrap($column);
//        $baseColumnIndex = $this->wrap($baseColumn);
//
//        if ($isNot) {
//            $queryStr = "NOT FIND_IN_SET({$baseColumnIndex}, IFNULL(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''), ''))";
//        } else {
//            $queryStr = "FIND_IN_SET({$baseColumnIndex}, REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";
//        }
//
//        $func = $isOr ? 'orWhereRaw' : 'whereRaw';
//        $builder->{$func}($queryStr);
//
//        return $builder;
    }


    /**
     * Get cast column string
     *
     * @param string $type
     * @param string $column
     * @param array $options
     * @return string
     */
    public function getCastColumn($type, $column, $options = [])
    {
        $cast = $this->getCastString($type, $column, $options);

        $column = $this->wrap($column);

        return "convert($cast, $column)";
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
                return 'int';
            case DatabaseDataType::TYPE_DECIMAL:
                return 'decimal';
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                return 'nvarchar';
            case DatabaseDataType::TYPE_DATE:
                return 'date';
            case DatabaseDataType::TYPE_DATETIME:
                return 'datetime2(0)';
            case DatabaseDataType::TYPE_TIME:
                return 'time(0)';
        }
        return 'nvarchar';
    }



    /**
     * Get cast string
     *
     * @param string $type
     * @param bool $addOption
     * @param array $options
     * @return string
     */
    public function getCastString($type, $addOption = false, $options = [])
    {
        $cast = '';
        switch ($type) {
            case DatabaseDataType::TYPE_INTEGER:
                $cast = 'int';
                break;
            case DatabaseDataType::TYPE_DECIMAL:
                $cast = 'decimal';
                break;
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                $cast = 'nvarchar';
                break;
            case DatabaseDataType::TYPE_DATE:
                $cast = 'date';
                break;
            case DatabaseDataType::TYPE_DATETIME:
                $cast = 'datetime2(0)';
                break;
            case DatabaseDataType::TYPE_TIME:
                $cast = 'time(0)';
                break;
        }

        if (!$addOption) {
            return $cast;
        }

        $length = array_get($options, 'length') ?? 50;

        switch ($type) {
            case DatabaseDataType::TYPE_DECIMAL:
                $decimal_digit = array_get($options, 'decimal_digit') ?? 2;
                $length = ($length > 38 ? 38 : $length);
                $cast .= "($length, $decimal_digit)";
                break;

            case DatabaseDataType::TYPE_STRING:
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
                return "format(datepart(YEAR, $column), '0000')";
            case GroupCondition::YM:
                return "format(datepart(YEAR, $column), '0000') + '-' + format(datepart(MONTH, $column), '00')";
            case GroupCondition::YMD:
                return "format(datepart(YEAR, $column), '0000') + '-' + format(datepart(MONTH, $column), '00') + '-' + format(datepart(DAY, $column), '00')";
            case GroupCondition::M:
                return "format(datepart(MONTH, $column), '00')";
            case GroupCondition::D:
                return "format(datepart(DAY, $column), '00')";
            case GroupCondition::W:
                if ($groupBy) {
                    return "datepart(WEEKDAY, $column)";
                }
                return $this->getWeekdayCaseWhenQuery("datepart(WEEKDAY, $column)");
            case GroupCondition::YMDHIS:
                return "format(datepart(YEAR, $column), '0000') + '-' + format(datepart(MONTH, $column), '00') + '-' + format(datepart(DAY, $column), '00') + ' ' + format(datepart(HOUR, $column), '00') + ':' + format(datepart(MINUTE, $column), '00') + ':' + format(datepart(SECOND, $column), '00')";
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
     * Get case when query
     * *Convert starting 0 start*
     *
     * @return string
     */
    protected function getWeekdayCaseWhenQuery($str)
    {
        $queries = [];

        // get weekday and no list
        $weekdayNos = $this->getWeekdayNolist();

        foreach ($weekdayNos as $no => $weekdayKey) {
            $queries[] = "when {$no} then '$weekdayKey'";
        }

        $queries[] = "else ''";

        $when = implode(" ", $queries);
        return "(case {$str} {$when} end)";
    }

    protected function getWeekdayNolist()
    {
        // fixed mysql server
        return [
            '1' => '0',
            '2' => '1',
            '3' => '2',
            '4' => '3',
            '5' => '4',
            '6' => '5',
            '7' => '6',
        ];
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        if (strtoupper($sequence) == 'ID' && array_has($values, $sequence) && isset($values[$sequence])) {
            // set IDENTITY_INSERT in query.
            return $this->compileEnableIdentityInsert($query->from) . parent::compileInsertGetId($query, $values, $sequence) . $this->compileDisableIdentityInsert($query->from);
        }
        return parent::compileInsertGetId($query, $values, $sequence);
    }

    /**
     * Compile the query to set enable IDENTITY_INSERT
     *
     * @param string $tableName
     * @return string query string
     */
    public function compileEnableIdentityInsert(string $tableName)
    {
        return "SET IDENTITY_INSERT {$this->wrapTable($tableName)} ON;  ";
    }

    /**
     * Compile the query to set diesable IDENTITY_INSERT
     *
     * @param string $tableName
     * @return string
     */
    public function compileDisableIdentityInsert(string $tableName)
    {
        return "SET IDENTITY_INSERT {$this->wrapTable($tableName)} OFF; ";
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
        return $this->wrap($value, $prefixAlias);
    }
}

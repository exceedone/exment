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
    public function isSupportWhereInMultiple() : bool
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
        $queryStr = "STRING_SPLIT(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''), ',')";

        // definition table name
        $tableNameAs = "{$tableName}_exists";
        $tableNameWrap = $this->wrapTable($tableName);
        $tableNameWrapAs = $this->wrapTable($tableNameAs);

        // CREATE "CROSS APPLY"
        $fromRaw = "$tableNameWrap as $tableNameWrapAs CROSS APPLY $queryStr AS CROSS_APPLY_TABLE";

        $func = $isNot ? 'whereNotExists' : 'whereExists';
        $builder->{$func}(function ($query) use ($values, $fromRaw, $tableNameAs, $tableNameWrap, $tableNameWrapAs) {
            $query->select(\DB::raw(1))
                ->fromRaw($fromRaw)
                ->whereRaw("$tableNameWrapAs.id = $tableNameWrap.id")
                ->whereIn("CROSS_APPLY_TABLE.value", toArray($values));
        });

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
                return 'datetime';
            case DatabaseDataType::TYPE_TIME:
                return 'time';
        }
        return 'nvarchar';
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
     * @return void
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
        }

        return null;
    }

    /**
     * convert carbon date to date format
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param \Carbon\Carbon $carbon
     *
     * @return string
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
        }

        return null;
    }

    /**
     * Get case when query
     *
     * @return string
     */
    protected function getWeekdayCaseWhenQuery($str)
    {
        $queries = [];

        // get weekday and no list
        $weekdayNos = $this->getWeekdayNolist();

        foreach ($weekdayNos as $no => $weekdayKey) {
            $weekday = exmtrans('common.weekday.' . $weekdayKey);
            $queries[] = "when {$no} then '$weekday'";
        }

        $queries[] = "else ''";

        $when = implode(" ", $queries);
        return "(case {$str} {$when} end)";
    }

    protected function getWeekdayNolist()
    {
        return [
            '1' => 'sun',
            '2' => 'mon',
            '3' => 'tue',
            '4' => 'wed',
            '5' => 'thu',
            '6' => 'fri',
            '7' => 'sat',
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

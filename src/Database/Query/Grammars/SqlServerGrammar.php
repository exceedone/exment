<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\SqlServerGrammar as BaseGrammar;
use Illuminate\Database\Query\Builder;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\GroupCondition;

class SqlServerGrammar extends BaseGrammar
{
    use GrammarTrait;
    
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
    public function getDateFormatString($groupCondition, $column, $groupBy = false)
    {
        $column = $this->wrap($column);

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

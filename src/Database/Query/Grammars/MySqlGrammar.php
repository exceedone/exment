<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\MySqlGrammar as BaseGrammar;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\GroupCondition;

class MySqlGrammar extends BaseGrammar
{
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
                $cast = 'varchar';
                break;
            case DatabaseDataType::TYPE_DATE:
                $cast = 'date';
                break;
            case DatabaseDataType::TYPE_DATETIME:
                $cast = 'datetime';
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
     *
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
                if ($groupBy) {
                    return "date_format($column, '%w')";
                }
                return $this->getWeekdayCaseWhenQuery("date_format($column, '%w')");
        }

        return null;
    }
    
    /**
     * convert carbon date to date format
     *
     * @param GroupCondition $groupCondition Y, YM, YMD, ...
     * @param Carbon $carbon
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
     * @return void
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
            '0' => 'sun',
            '1' => 'mon',
            '2' => 'tue',
            '3' => 'wed',
            '4' => 'thu',
            '5' => 'fri',
            '6' => 'sat',
            '7' => 'sun',
        ];
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

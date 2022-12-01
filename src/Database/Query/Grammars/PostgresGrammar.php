<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\PostgresGrammar as BaseGrammar;
use Illuminate\Database\Query\Builder;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\GroupCondition;

class PostgresGrammar extends BaseGrammar implements GrammarInterface
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
        // $index = $this->wrap($column);
        $index = $this->getCastColumn(DatabaseDataType::TYPE_STRING, $column);
        $queryStr = "REGEXP_SPLIT_TO_TABLE(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\"', ''), ',')";

        // definition table name
        $tableNameAs = "{$tableName}_exists";
        $tableNameWrap = $this->wrapTable($tableName);
        $tableNameWrapAs = $this->wrapTable($tableNameAs);

        // CREATE "CROSS APPLY"
        $fromRaw = "(select id, $queryStr as VALUE from $tableNameWrap) as $tableNameWrapAs";

        $func = $isNot ? 'whereNotExists' : 'whereExists';
        $builder->{$func}(function ($query) use ($values, $fromRaw, $tableNameAs, $tableNameWrap, $tableNameWrapAs) {
            $query->select(\DB::raw(1))
                // fromRaw is wrapped.
                ->fromRaw($fromRaw)
                // $tableNameWrapAs and $tableNameWrap is wrapped.
                ->whereRaw("$tableNameWrapAs.id = $tableNameWrap.id")
                ->whereIn("$tableNameAs.value", toArray($values));
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
        $queryStr = "REGEXP_SPLIT_TO_ARRAY(REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\"', ''), ',')";

        $grammar = \DB::getQueryGrammar();
        $cast_column = $grammar->getCastColumn(DatabaseDataType::TYPE_STRING, $baseColumn);

        $func = $isOr ? 'orWhereRaw' : 'whereRaw';
        $sign = $isNot ? '<>' : '=';
        $builder->{$func}("$cast_column $sign ANY($queryStr)");

        return $builder;
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

        return "cast($column as $cast)";
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
                return 'integer';
            case DatabaseDataType::TYPE_DECIMAL:
                return 'decimal';
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                return 'text';
            case DatabaseDataType::TYPE_DATE:
                return 'date';
            case DatabaseDataType::TYPE_DATETIME:
                return 'timestamp';
            case DatabaseDataType::TYPE_TIME:
                return 'time';
        }
        return 'text';
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
                $cast = 'integer';
                break;
            case DatabaseDataType::TYPE_DECIMAL:
                $cast = 'decimal';
                break;
            case DatabaseDataType::TYPE_STRING:
            case DatabaseDataType::TYPE_STRING_MULTIPLE:
                $cast = 'text';
                break;
            case DatabaseDataType::TYPE_DATE:
                $cast = 'date';
                break;
            case DatabaseDataType::TYPE_DATETIME:
                $cast = 'timestamp';
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
            $column = $this->getCastColumn(DatabaseDataType::TYPE_DATETIME, $column);
        } elseif ($this->isJsonSelector($column)) {
            $column = $this->wrapJsonUnquote($column);
        }

        switch ($groupCondition) {
            case GroupCondition::Y:
                return "to_char($column, 'YYYY')";
            case GroupCondition::YM:
                return "to_char($column, 'YYYY-MM')";
            case GroupCondition::YMD:
                return "to_char($column, 'YYYY-MM-DD')";
            case GroupCondition::M:
                return "to_char($column, 'MM')";
            case GroupCondition::D:
                return "to_char($column, 'DD')";
            case GroupCondition::W:
                return "date_part('dow', $column)";
            case GroupCondition::YMDHIS:
                return "to_char($column, 'YYYY-MM-DD HH24:MI:SS')";
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
        return $this->wrap($value, $prefixAlias);
    }
}

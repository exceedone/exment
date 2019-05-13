<?php

namespace Exceedone\Exment\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\SqlServerGrammar as BaseGrammar;
use Illuminate\Database\Query\Builder;
use Exceedone\Exment\Enums\DatabaseDataType;

class SqlServerGrammar extends BaseGrammar
{
    /**
     * Get cast column string
     *
     * @return string
     */
    public function getCastColumn($type, $column, $options = []){
        $cast = $this->getCastString($type, $column, $options);

        $column = $this->wrap($column);

        return "convert($cast, $column)";
    }

    /**
     * Get cast string
     *
     * @return string
     */
    public function getCastString($type, $addOption = false, $options = []){
        $cast = '';
        switch($type){
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
        }

        if(!$addOption){
            return $cast;
        }
        
        $length = array_get($options, 'length') ?? 50;

        switch($type){
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
     * Compile an insert and get ID statement into SQL.
     *
     * @param  Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        if(strtoupper($sequence) == 'ID' && array_has($values, $sequence) && isset($values[$sequence])){
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
     * @return void
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
    public function wrapJsonUnquote($value, $prefixAlias = false){
        return $this->wrap($value, $prefixAlias);
    }
}

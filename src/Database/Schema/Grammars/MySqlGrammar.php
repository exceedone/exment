<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MySqlGrammar as BaseGrammar;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Enums\ColumnType;

class MySqlGrammar extends BaseGrammar implements GrammarInterface
{
    /**
     * Compile the query to get version
     *
     * @return string
     */
    public function compileGetVersion()
    {
        return "select version()";
    }

    /**
     * Compile the query to show tables
     *
     * @return string
     */
    public function compileGetTableListing()
    {
        return "show full tables where Table_Type = 'BASE TABLE'";
    }

    /**
     * Compile the query to get column difinitions
     *
     * @return string
     */
    public function compileColumnDefinitions($tableName)
    {
        return "show columns from {$this->wrapTable($tableName)}";
    }

    /**
     * Compile the query to Create Value Table
     *
     * @return string
     */
    public function compileCreateValueTable(string $tableName)
    {
        return "create table if not exists {$this->wrapTable($tableName)} like custom_values";
    }

    /**
     * Compile the query to determine the list of tables.
     *
     * @return string
     */
    public function compileCreateRelationValueTable(string $tableName)
    {
        return "create table if not exists {$this->wrapTable($tableName)} like custom_relation_values";
    }
    
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name, CustomColumn $custom_column)
    {
        $cast_type = null;
        $column_type = null;
        switch($custom_column->column_type) {
            case ColumnType::INTEGER:
                $cast_type = 'SIGNED';
                $column_type = 'BIGINT';
                break;
            case ColumnType::DECIMAL:
            case ColumnType::CURRENCY:
                $decimal_digit = $custom_column->getOption('decimal_digit') ?? 2;
                $number_digit = 12 + $decimal_digit;
                $cast_type = "DECIMAL($number_digit, $decimal_digit)";
                break;
            case ColumnType::DATE:
                $cast_type = 'DATE';
                break;
            case ColumnType::DATETIME:
                $cast_type = 'DATETIME';
                break;
            case ColumnType::TIME:
                $cast_type = 'TIME';
                break;
        }
        // ALTER TABLE
        $as_value = "json_unquote(json_extract({$this->wrap('value')},'$.\"{$json_column_name}\"'))";
        
        if (isset($cast_type)) {
            $as_value = "CAST({$as_value} AS {$cast_type})";
        }

        if (!isset($column_type)) {
            $column_type = $cast_type ?? 'nvarchar(768)';
        }

        return [
            "alter table {$db_table_name} add {$db_column_name} {$column_type} generated always as ({$as_value}) virtual",
            "alter table {$db_table_name} add index {$index_name}({$db_column_name})",
        ];
    }
    
    public function compileGetIndex($tableName)
    {
        return $this->_compileGetIndex($tableName, false);
    }
    
    public function compileGetUnique($tableName)
    {
        return $this->_compileGetIndex($tableName, true);
    }

    protected function _compileGetIndex($tableName, $unique)
    {
        return "show index from {$this->wrapTable($tableName)} where non_unique = :non_unique and column_name = :column_name";
    }
    
    public function compileGetConstraint($tableName)
    {
        return null;
    }
}

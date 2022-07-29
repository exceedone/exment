<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MySqlGrammar as BaseGrammar;
use Exceedone\Exment\Model\CustomColumn;

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
        // ALTER TABLE
        $as_value = "json_unquote(json_extract({$this->wrap('value')},'$.\"{$json_column_name}\"'))";

        return [
            "alter table {$db_table_name} add {$db_column_name} nvarchar(768) generated always as ({$as_value}) virtual",
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

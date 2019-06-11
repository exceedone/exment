<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MySqlGrammar as BaseGrammar;

class MySqlGrammar extends BaseGrammar
{
    /**
     * Compile the query to get version
     *
     * @return string
     */
    public function compileGetVersion()
    {
        return "select version";
    }

    /**
     * Compile the query to show tables
     *
     * @return string
     */
    public function compileGetTableListing()
    {
        return "show tables";
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
    
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name)
    {
        // ALTER TABLE
        $as_value = "json_unquote(json_extract({$this->wrap('value')},'$.{$json_column_name}'))";

        return [
            "alter table {$db_table_name} add {$db_column_name} nvarchar(768) generated always as ({$as_value}) virtual",
            "alter table {$db_table_name} add index {$index_name}({$db_column_name})",
        ];
    }
    
    public function compileDropIndexColumn($db_table_name, $db_column_name, $index_name)
    {
        return [
            "alter table {$db_table_name} drop index {$index_name}",
            "alter table {$db_table_name} drop column {$db_column_name}",
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
        $unique_key = boolval($unique) ? 0 : 1;
        return "show index from {$this->wrapTable($tableName)} where non_unique = $unique_key and column_name = ?";
    }
}

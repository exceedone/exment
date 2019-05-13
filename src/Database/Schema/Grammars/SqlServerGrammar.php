<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\SqlServerGrammar as BaseGrammar;

class SqlServerGrammar extends BaseGrammar
{
    /**
     * Compile the query to show tables
     *
     * @return string
     */
    public function compileGetTableListing()
    {
        return "select table_name from INFORMATION_SCHEMA.TABLES where TABLE_TYPE='BASE TABLE'";
    }

    /**
     * Compile the query to Create Value Table
     *
     * @return string
     */
    public function compileCreateValueTable(string $tableName)
    {
        return "if object_id('{$this->wrapTable($tableName)}') is null select * into {$this->wrapTable($tableName)} from custom_values";
    }

    /**
     * Compile the query to determine the list of tables.
     *
     * @return string
     */
    public function compileCreateRelationValueTable(string $tableName)
    {
        return "if object_id('{$this->wrapTable($tableName)}') is null select * into {$this->wrapTable($tableName)} from custom_relation_values";
    }
    
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name)
    {
        // ALTER TABLE
        $as_value = "JSON_VALUE(\"value\",'$.$json_column_name')";

        return [
            "alter table {$db_table_name} add {$db_column_name} as {$as_value}",
            //"alter table {$db_table_name} add index {$index_name}({$db_column_name})",
        ];
    }
    
    public function compileDropIndexColumn($db_table_name, $db_column_name, $index_name)
    {
        // ALTER TABLE
        $as_value = "json_unquote(json_extract(`value`,'$.$json_column_name'))";

        return [
            "alter table {$db_table_name} drop index {$index_name}",
            "alter table {$db_table_name} drop column {$db_column_name}",
        ];
    }
    
    public function compileGetIndex($tableName){
        return $this->_compileGetIndex($tableName, false);
    }
    
    public function compileGetUnique($tableName){
        return $this->_compileGetIndex($tableName, true);
    }

    protected function _compileGetIndex($tableName, $unique){
        $unique_key = boolval($unique) ? 1 : 0;
        return "select
            COL_NAME(ic.object_id, ic.column_id) as column_name,
            i.is_unique as is_unique,
            i.name as key_name
        from
            sys.indexes AS i
        inner join
            sys.index_columns as ic
        on  i.object_id = ic.object_id
        and i.index_id = ic.index_id
        where
            i.type = 2
        and i.object_id = OBJECT_ID('{$this->wrapTable($tableName)}')
        and i.is_unique = {$unique_key}
        and COL_NAME(ic.object_id, ic.column_id) = ?";
    }
}

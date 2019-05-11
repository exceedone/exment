<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MySqlGrammar as BaseMySqlGrammar;

class MySqlGrammar extends BaseMySqlGrammar
{
    /**
     * Compile the query to Create Value Table
     *
     * @return string
     */
    public function compileCreateValueTable(string $table)
    {
        return "create table if not exists {$this->wrapTable($table)} like custom_values";
    }

    /**
     * Compile the query to determine the list of tables.
     *
     * @return string
     */
    public function compileCreateRelationValueTable(string $table)
    {
        return "create table if not exists {$this->wrapTable($table)} like custom_relation_values";
    }
    
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name)
    {
        // ALTER TABLE
        $as_value = "json_unquote(json_extract(`value`,'$.$json_column_name'))";

        return [
            "alter table {$db_table_name} add {$db_column_name} nvarchar(768) generated always as ({$as_value}) virtual",
            "alter table {$db_table_name} add index {$index_name}({$db_column_name})",
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
    
    public function compileGetIndex(){
        return $this->_compileGetIndex(false);
    }
    
    public function compileGetUnique(){
        return $this->_compileGetIndex(true);
    }

    protected function _compileGetIndex($unique){
        $unique_key = boolval($unique) ? 0 : 1;
        return "show index from ? where non_unique = $unique_key and column_name = ?";
    }
}

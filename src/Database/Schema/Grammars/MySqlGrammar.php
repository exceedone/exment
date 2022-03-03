<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Grammars\MySqlGrammar as BaseGrammar;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;

class MySqlGrammar extends BaseGrammar implements GrammarInterface
{
    use GrammarTrait;

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
    
    /**
     * Create custom view's index
     *
     * @param CustomView $custom_view
     * @return array
     */
    public function compileCustomViewIndexColumn(CustomView $custom_view) : array
    {
        $info = $this->getCustomViewIndexColumnInfo($custom_view);
        $db_table_name = $info['db_table_name'];
        $pure_db_table_name = $info['pure_db_table_name'];
        $custom_view_filter_columns = $info['custom_view_filter_columns'];
        $custom_view_sort_columns = $info['custom_view_sort_columns'];
        $custom_view_filter_indexname = $info['custom_view_filter_indexname'];
        $custom_view_sort_indexname = $info['custom_view_sort_indexname'];
        $has_filter_columns = $info['has_filter_columns'];
        $has_sort_columns = $info['has_sort_columns'];

        $custom_view_filter_column = $custom_view_filter_columns->implode(',');
        $custom_view_sort_column = $custom_view_sort_columns->implode(',');

        $result = [];
        if(boolval($has_filter_columns)){
            $result[] = "IF EXISTS ( SELECT * FROM INFORMATION_SCHEMA.STATISTICS  WHERE TABLE_NAME = '{$pure_db_table_name}' AND INDEX_NAME = '{$custom_view_filter_indexname}') THEN ALTER TABLE  {$db_table_name} DROP index {$custom_view_filter_indexname}; END IF;";
            $result[] = "alter table {$db_table_name} add index {$custom_view_filter_indexname}({$custom_view_filter_column})";
        }
        if(boolval($has_sort_columns)){
            $result[] = "IF EXISTS ( SELECT * FROM INFORMATION_SCHEMA.STATISTICS  WHERE TABLE_NAME = '{$pure_db_table_name}' AND INDEX_NAME = '{$custom_view_sort_indexname}') THEN ALTER TABLE  {$db_table_name} DROP index {$custom_view_sort_indexname}; END IF;";
            $result[] = "alter table {$db_table_name} add index {$custom_view_sort_indexname}({$custom_view_sort_column})";
        }

        return $result;
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

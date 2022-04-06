<?php

namespace Exceedone\Exment\Database\Schema\Grammars;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use Exceedone\Exment\Model\CustomColumn;
use Illuminate\Database\Schema\Grammars\PostgresGrammar as BaseGrammar;

class PostgresGrammar extends BaseGrammar implements GrammarInterface
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
        return "select table_catalog,table_schema,table_name,table_type from information_schema.tables where table_schema = 'public';";
    }

    /**
     * Compile the query to get column difinitions
     *
     * @return string
     */
    public function compileColumnDefinitions($tableName)
    {
        //ToDo
        return "select table_name as table_name, column_name as column_name, NULL as [type], NULL as virtual, is_nullable as nullable from information_schema.columns where table_name = {$this->wrapTable($tableName)};";
    }

    /**
     * Compile the query to Create Value Table
     *
     * @return string
     */
    public function compileCreateValueTable(string $tableName)
    {
        return "create table if not exists {$this->wrapTable($tableName)} (like custom_values INCLUDING ALL)";
    }

    /**
     * Compile the query to determine the list of tables.
     *
     * @return string
     */
    public function compileCreateRelationValueTable(string $tableName)
    {
        return "create table if not exists {$this->wrapTable($tableName)} (like custom_relation_values INCLUDING ALL)";
    }
    
    public function compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name, CustomColumn $custom_column)
    {
        // ALTER TABLE
        $as_value = $this->wrapJsonSelector("value->{$json_column_name}");

        return [
            "alter table {$db_table_name} add column {$db_column_name} text generated always as ({$as_value}) STORED",
            "create index {$index_name} on {$db_table_name}({$db_column_name})",
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
        return "select tablename as table_name, indexname as key_name, indexdef from pg_indexes where schemaname = 'public' and tablename = :table_name";
    }
    
    public function compileGetConstraint($tableName)
    {
        return null;
    }

    
    /**
     * Wrap the given JSON selector.
     * *Copyed from vendor\laravel\framework\src\Illuminate\Database\Query\Grammars\PostgresGrammar.php*
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapJsonSelector($value)
    {
        $path = explode('->', $value);

        $field = $this->wrapSegments(explode('.', array_shift($path)));

        $wrappedPath = $this->wrapJsonPathAttributes($path);

        $attribute = array_pop($wrappedPath);

        if (! empty($wrappedPath)) {
            return $field.'->'.implode('->', $wrappedPath).'->>'.$attribute;
        }

        return $field.'->>'.$attribute;
    }

    /**
     * Wrap the attributes of the give JSON path.
     * *Copyed from vendor\laravel\framework\src\Illuminate\Database\Query\Grammars\PostgresGrammar.php*
     *
     * @param  array  $path
     * @return array
     */
    protected function wrapJsonPathAttributes($path)
    {
        return array_map(function ($attribute) {
            return filter_var($attribute, FILTER_VALIDATE_INT) !== false
                        ? $attribute
                        : "'$attribute'";
        }, $path);
    }
}

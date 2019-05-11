<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\Builder as BaseBuilder;

trait BuilderTrait
{
    /**
     * Create Value Table if it not exists.
     *
     * @param  string  $table
     * @return void
     */
    public function createValueTable($table)
    {
        $table = $this->connection->getTablePrefix().$table;
        $this->connection->statement(
            $this->grammar->compileCreateValueTable($table)
        );
    }

    /**
     * Create Relation Value Table if it not exists.
     *
     * @param  string  $table
     * @return void
     */
    public function createRelationValueTable($table)
    {
        $table = $this->connection->getTablePrefix().$table;
        $this->connection->statement(
            $this->grammar->compileCreateRelationValueTable($table)
        );
    }

    /**
     *  Add Virtual Column and Index 
     *
     * @param string $db_table_name
     * @param string $db_column_name
     * @param string $index_name
     * @param string $json_column_name
     * @return void
     */
    public function alterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name)
    {
        if(!\Schema::hasTable($db_table_name)){
            return;
        }

        $db_table_name = $this->connection->getTablePrefix().$db_table_name;

        $sqls = $this->grammar->compileAlterIndexColumn($db_table_name, $db_column_name, $index_name, $json_column_name);

        foreach($sqls as $sql){
            $this->connection->statement($sql);
        }
    }

    /**
     *  Drop Virtual Column and Index 
     *
     * @param string $db_table_name
     * @param string $db_column_name
     * @param string $index_name
     * @return void
     */
    public function dropIndexColumn($db_table_name, $db_column_name, $index_name)
    {
        if(!\Schema::hasTable($db_table_name)){
            return;
        }

        $db_table_name = $this->connection->getTablePrefix().$db_table_name;

        $sqls = $this->grammar->compileDropIndexColumn($db_table_name, $db_column_name, $index_name);

        foreach($sqls as $sql){
            $this->connection->statement($sql);
        }
    }

    public function getIndex($tableName, $columnName)
    {
        if(!\Schema::hasTable($tableName)){
            return collect([]);
        }

        $tableName = $this->connection->getTablePrefix().$tableName;

        $sql = $this->grammar->compileGetIndex();

        return $this->connection->selectFromWriteConnection($sql, [$tableName, $columnName]);
    }

    public function getUnique($tableName, $columnName)
    {
        if(!\Schema::hasTable($tableName)){
            return collect([]);
        }

        $tableName = $this->connection->getTablePrefix().$tableName;

        $sql = $this->grammar->compileGetUnique();

        return $this->connection->selectFromWriteConnection($sql, [$tableName, $columnName]);
    }
}

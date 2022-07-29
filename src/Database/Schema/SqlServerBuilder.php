<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\SqlServerBuilder as BaseBuilder;

class SqlServerBuilder extends BaseBuilder
{
    use BuilderTrait;

    protected function getUniqueIndexDefinitionsSelect($sql, $tableName, $columnName, $unique)
    {
        return $this->connection->select($sql, ['column_name' => $columnName, 'is_unique' => $unique]);
    }

    /**
     * Check mariadb
     *
     * @return bool
     */
    public function isMariaDB()
    {
        return false;
    }

    /**
     * Check sqlserver
     *
     * @return bool
     */
    public function isSqlServer()
    {
        return true;
    }

    /**
     * Get column difinitions
     *
     * @return array
     */
    public function getColumnDefinitions($table)
    {
        $baseTable = $table;
        $table = $this->connection->getTablePrefix().$table;
        $results = $this->connection->selectFromWriteConnection($this->grammar->compileColumnDefinitions($table), [$table]);

        return $this->connection->getPostProcessor()->processColumnDefinitions($baseTable, $results);
    }

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
        $this->connection->statement(
            $this->grammar->compileAlterPrimaryKey($table)
        );
    }
}

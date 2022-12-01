<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\PostgresBuilder as BaseBuilder;

class PostgresBuilder extends BaseBuilder
{
    use BuilderTrait;
    
    protected function getUniqueIndexDefinitionsSelect($sql, $tableName, $columnName, $unique)
    {
        return $this->connection->select($sql, ['table_name' => $tableName]);
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
     * Check whether casting column compare
     *
     * @return bool
     */
    public function isCastColumnCompare()
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

        $sqls = $this->grammar->compileCreateValueTable($table);
        foreach ($sqls as $sql) {
            $this->connection->statement($sql);
        }
    }
    
    /**
     * update sequence for column default.
     *
     * @param string $table
     * @param integer $value
     * @param string $columnName
     * @return void
     */
    public function updateDefaultSequence($table, $value, $columnName = 'id')
    {
        $table = $this->connection->getTablePrefix().$table;
        $sequenceName = "{$table}_{$columnName}_seq";

        $current_value = $this->connection->select(
            $this->grammar->compileGetCurrentSequence($sequenceName)
        );

        if (count($current_value) > 0) {
            $current_value = $current_value[0]->last_value;
        }

        if ($current_value >= $value) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileSetSequence($sequenceName, $value)
        );
    }
}

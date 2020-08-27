<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\SqlServerBuilder as BaseBuilder;

class SqlServerBuilder extends BaseBuilder
{
    use BuilderTrait;
    
    protected function getUniqueIndexDefinitionsSelect($sql, $tableName, $columnName, $unique){
        return $this->connection->select($sql, ['column_name' => $columnName, 'is_unique' => $unique]);
    }

    /**
     * Check mariadb
     *
     * @return void
     */
    public function isMariaDB()
    {
        return false;
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
}

<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\SqlServerBuilder as BaseBuilder;

class SqlServerBuilder extends BaseBuilder
{
    use BuilderTrait;
    
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

<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\MySqlBuilder as BaseBuilder;

class MySqlBuilder extends BaseBuilder
{
    use BuilderTrait;
    
    protected function getUniqueIndex($tableName, $columnName, $unique)
    {
        if (!\Schema::hasTable($tableName)) {
            return collect([]);
        }

        $tableName = $this->connection->getTablePrefix().$tableName;

        $sql = $unique ? $this->grammar->compileGetUnique($tableName) : $this->grammar->compileGetIndex($tableName);

        $rows = $this->connection->select($sql, [$columnName]);

        return collect($rows)->map(function ($row) {
            return [
                'table_name' => $row->table,
                'column_name' => $row->column_name,
                'key_name' => $row->key_name,
                'unique' => boolval($row->non_unique),
            ];
        });
    }
}

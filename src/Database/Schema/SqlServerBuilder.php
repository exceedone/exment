<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\SqlServerBuilder as BaseBuilder;

class SqlServerBuilder extends BaseBuilder
{
    use BuilderTrait;

    protected function getUniqueIndex($tableName, $columnName, $unique){
        if(!\Schema::hasTable($tableName)){
            return collect([]);
        }

        $baseTableName = $tableName;
        $tableName = $this->connection->getTablePrefix().$tableName;

        $sql = $unique ? $this->grammar->compileGetUnique($tableName) : $this->grammar->compileGetIndex($tableName);

        $rows = $this->connection->select($sql, [$columnName]);

        return collect($rows)->map(function($row) use($baseTableName){
            return [
                'table_name' => $baseTableName,
                'column_name' => $row->column_name,
                'key_name' => $row->key_name,
                'unique' => boolval($row->is_unique),
            ];
        });
    }
}

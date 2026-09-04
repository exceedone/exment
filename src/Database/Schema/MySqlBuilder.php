<?php

namespace Exceedone\Exment\Database\Schema;

use Illuminate\Database\Schema\MySqlBuilder as BaseBuilder;

class MySqlBuilder extends BaseBuilder
{
    use BuilderTrait;

    // @phpstan-ignore-next-line
    protected function getUniqueIndexDefinitionsSelect($sql, $tableName, $columnName, $unique)
    {
        return $this->connection->select($sql, ['column_name' => $columnName, 'non_unique' => !$unique]);
    }
}

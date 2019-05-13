<?php

namespace Exceedone\Exment\Database\Query\Processors;

use Illuminate\Database\Query\Processors\SqlServerProcessor as BaseSqlServerProcessor;

class SqlServerProcessor extends BaseSqlServerProcessor
{
    /**
     * Process the results of a table listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processTableListing($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->table_name;
        }, $results);
    }
    
    /**
     * Process the results of a index listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processIndexListing($tableName, $results)
    {
        return collect($results)->map(function ($result) use($tableName) {
            return [
                'table_name' => $tableName,
                'column_name' => $result->column_name,
                'key_name' => $result->key_name,
                'unique' => boolval($result->is_unique),
            ];
        })->toArray();
    }
}

<?php

namespace Exceedone\Exment\Database\Query\Processors;

use Illuminate\Database\Query\Processors\SqlServerProcessor as BaseSqlServerProcessor;

class SqlServerProcessor extends BaseSqlServerProcessor
{
    /**
     * Process the results of a get version.
     *
     * @param  array  $results
     * @return string|null
     */
    public function processGetVersion($results)
    {
        $string = collect((array)$results[0])->first();

        // match regex
        preg_match('/\d+\.\d+\.\d+\.\d+/u', $string, $m);
        if (!$m) {
            return null;
        }
        return $m[0];
    }

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
     * Process the results of a Column Definitions query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnDefinitions($tableName, $results)
    {
        return collect($results)->map(function ($result) {
            return [
                'table_name' => $result->table_name,
                'column_name' => $result->column_name,
                'type' => $result->type,
                'nullable' => boolval($result->nullable),
                'virtual' => boolval($result->virtual),
            ];
        })->toArray();
    }

    /**
     * Process the results of a index listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processIndexDefinitions($tableName, $results)
    {
        return collect($results)->map(function ($result) use ($tableName) {
            return [
                'table_name' => $tableName,
                'column_name' => $result->column_name,
                'key_name' => $result->key_name,
                'unique' => boolval($result->is_unique),
            ];
        })->toArray();
    }

    /**
     * Process the results of a constraints listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processConstraints($results)
    {
        return collect($results)->map(function ($result) {
            return array_get((array)$result, 'name');
        })->filter()->toArray();
    }
}

<?php

namespace Exceedone\Exment\Database\Query\Processors;

use Illuminate\Database\Query\Processors\MySqlProcessor as BaseMySqlProcessor;

class MySqlProcessor extends BaseMySqlProcessor
{
    /**
     * Process the results of a get version.
     *
     * @param  array  $results
     * @return string
     */
    public function processGetVersion($results)
    {
        $versionArray = $this->versionAray($results);

        return $versionArray[0];
    }

    /**
     * Process the results of a mariadb
     *
     * @param  array  $results
     * @return array|bool
     */
    public function processIsMariaDB($results)
    {
        $versionArray = $this->versionAray($results);

        if (count($versionArray) <= 1) {
            return false;
        }

        return strtolower($versionArray[1]) == 'mariadb';
    }

    protected function versionAray($results)
    {
        $version = collect(collect($results)->first())->first();
        return explode('-', $version);
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
            return collect((object) $result)->first();
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
        return collect($results)->map(function ($result) use ($tableName) {
            return [
                'table_name' => $tableName,
                'column_name' => $result->field,
                'type' => $result->type,
                'nullable' => boolval($result->null),
                'virtual' => strtoupper($result->extra) == 'VIRTUAL GENERATED',
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
                'unique' => !boolval($result->non_unique),
            ];
        })->toArray();
    }
}

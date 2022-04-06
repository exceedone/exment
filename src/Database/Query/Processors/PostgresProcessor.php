<?php

namespace Exceedone\Exment\Database\Query\Processors;

use Illuminate\Database\Query\Processors\PostgresProcessor as BasePostgresProcessor;

class PostgresProcessor extends BasePostgresProcessor
{
    /**
     * Process the results of a get version.
     *
     * @param  array  $results
     * @return array
     */
    public function processGetVersion($results)
    {
        $string = collect((array)$results[0])->first();

        // match regex
        preg_match('/PostgreSQL (?<first>\d+)\.(?<second>\d+),/u', $string, $m);
        if (!$m) {
            return null;
        }
        return "{$m['first']}.{$m['second']}";
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
    public function processIndexDefinitions($tableName, $columnName, bool $unique, $results)
    {
        // Filtering whether unique and column_name, because cannot filter is unique using sql.
        return collect($results)->filter(function($result) use($unique, $columnName){
            // Check column name
            $indexdef = strtolower($result->indexdef);
            if(strpos($indexdef, "btree ({$columnName})") === false && strpos($indexdef, "btree({$columnName})") === false){
                return false;
            }
            if($unique && strpos($indexdef, "crfeate unique index") === false){
                return false;
            }
            if(!$unique && strpos($indexdef, "crfeate index") === false){
                return false;
            }
            
            return true;
        })->map(function ($result) use ($tableName, $columnName, $unique) {
            return [
                'table_name' => $tableName,
                'column_name' => $columnName,
                'key_name' => $result->key_name,
                'unique' => $unique,
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

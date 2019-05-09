<?php

namespace Exceedone\Exment\Services;

use \DB;
use \Exception;

class DynamicDBHelper
{
    public static function dropValueTable($table_name)
    {
        DB::statement("DROP TABLE IF EXISTS ".$table_name);
    }

    public static function createValueTable($table_name)
    {
        DB::statement("CREATE TABLE IF NOT EXISTS ".$table_name." LIKE custom_values");
    }

    public static function createRelationValueTable($pivot_table_name)
    {
        DB::statement("CREATE TABLE IF NOT EXISTS ".$pivot_table_name." LIKE custom_relation_values");
    }
    
    public static function alterIndexColumn($db_table_name, $db_column_name, $index_name, $column_name)
    {
        DB::beginTransaction();
        try {
            // ALTER TABLE
            $as_value = "json_unquote(json_extract(`value`,'$.$column_name'))";

            if(\Schema::hasTable($db_table_name)){
                DB::statement("ALTER TABLE $db_table_name ADD $db_column_name nvarchar(768) GENERATED ALWAYS AS ($as_value) VIRTUAL;");
                DB::statement("ALTER TABLE $db_table_name ADD index $index_name($db_column_name)");    
                DB::commit();
            }
        } catch (Exception $exception) {
            DB::rollback();
            throw $exception;
        }
    }

    public static function dropIndexColumn($db_table_name, $db_column_name, $index_name)
    {
        DB::beginTransaction();
        try {
            if(\Schema::hasTable($db_table_name)){
                // ALTER TABLE
                DB::statement("ALTER TABLE $db_table_name DROP INDEX $index_name;");
                DB::statement("ALTER TABLE $db_table_name DROP COLUMN $db_column_name;");
                DB::commit();
            }
        } catch (Exception $exception) {
            DB::rollback();
            throw $exception;
        }
    }

    public static function getDBIndex($db_table_name, $db_column_name, $unique = true){
        if(!\Schema::hasTable($db_table_name)){
            return collect([]);
        }

        $unique_key = boolval($unique) ? 0 : 1;
        return \DB::select("SHOW INDEX FROM $db_table_name WHERE non_unique = $unique_key AND column_name = '$db_column_name'");
    }
}

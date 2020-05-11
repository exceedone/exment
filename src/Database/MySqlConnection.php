<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\MySqlBuilder;
use Exceedone\Exment\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\MySqlConnection as BaseConnection;

class MySqlConnection extends BaseConnection
{
    use ConnectionTrait;

    protected static $isContainsColumnStatistics = null;
    
    /**
     * Get a schema builder instance for the connection.
     *
     * @return Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MySqlBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return MySqlGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
    }
    
    /**
     * Get the default query grammar instance.
     *
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar);
    }

    /**
     * Get the default post processor instance.
     *
     * @return MySqlProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MySqlProcessor;
    }

    
    /**
     * dumpDatabase mysqldump for backup table definition or table data.
     *
     * @param string backup target table (default:null)
     * @return void
     */
    protected function dumpDatabase($tempDir, $table = null)
    {
        // get table connect info
        $host = config('database.connections.mysql.host', '');
        $username = config('database.connections.mysql.username', '');
        $password = config('database.connections.mysql.password', '');
        $database = config('database.connections.mysql.database', '');
        $dbport = config('database.connections.mysql.port', '');

        // mysqldump v8.0 or later, append "column-statistics=0" option
        // https://serverfault.com/questions/912162/mysqldump-throws-unknown-table-column-statistics-in-information-schema-1109
        $column_statistics = static::isContainsColumnStatistics() ? '--column-statistics=0' : '';

        $mysqldump = static::getMysqlDumpPath();
        $command = sprintf(
            '%s %s -h %s -u %s --password=%s -P %s',
            $mysqldump,
            $column_statistics,
            $host,
            $username,
            $password,
            $dbport
        );

        if ($table == null) {
            $file = path_join($tempDir, config('exment.backup_info.def_file', 'table_definition.sql'));
            $command = sprintf('%s -d %s > %s', $command, $database, $file);
        } else {
            $file = sprintf('%s.sql', path_join($tempDir, $table));
            $command = sprintf('%s -t %s %s > %s', $command, $database, $table, $file);
        }

        exec($command);
    }

    /**
     * Is Contains "column-statistics" option
     *
     * @return bool
     */
    protected static function isContainsColumnStatistics()
    {
        if(!is_null(static::$isContainsColumnStatistics)){
            return static::$isContainsColumnStatistics;
        }
        $mysqldump = static::getMysqlDumpPath();
        $command = sprintf(
            '%s --help',
            $mysqldump
        );   
        exec($command, $output);

        static::$isContainsColumnStatistics = collect($output)->contains(function($o){
            return strpos($o, 'column-statistics') !== false;
        });

        return static::$isContainsColumnStatistics;
    }

    /**
     * Check execute backup database
     *
     * @return bool
     */
    public function checkBackup() : bool
    {
        $mysqldump = static::getMysqlDumpPath();

        exec("$mysqldump --version", $output);

        if(is_nullorempty($output)){
            return false;
        }
        return true;
    }   

    public function backupDatabase($tempDir)
    {
        // export table definition
        $this->dumpDatabase($tempDir);

        // get all table list
        $tables = \Schema::getTableListing();

        // backup each table
        foreach ($tables as $name) {
            if (stripos($name, 'exm__') === 0) {
                // backup table data which has virtual column
                $this->backupTable($tempDir, $name);
            } else {
                // backup table data with mysqldump
                $this->dumpDatabase($tempDir, $name);
            }
        }
    }
    
    /**
     * backup table data except virtual generated column.
     *
     * @param string backup target table
     */
    protected function backupTable($tempDir, $table)
    {
        // create tsv file
        $file = new \SplFileObject(path_join($tempDir, $table.'.tsv'), 'w');
        $file->setCsvControl("\t");

        // get column definition
        $columns = \Schema::getColumnDefinitions($table);

        // get output field name list (not virtual column)
        $outcols = [];
        foreach ($columns as $column) {
            if (!boolval($column['virtual'])) {
                $outcols[] = strtolower($column['column_name']);
            }
        }
        // write column header
        $file->fputcsv($outcols);

        // execute backup. contains soft deleted table
        \DB::table($table)->orderBy('id')->chunk(1000, function ($rows) use ($file, $outcols) {
            foreach ($rows as $row) {
                $array = (array)$row;
                $row = array_map(function ($key) use ($array) {
                    return $array[$key];
                }, $outcols);
                // write detail data
                $file->fputcsv($row);
            }
        });
    }

    protected static function getMysqlDumpPath()
    {
        return config('exment.backup_info.mysql_dir', '') . 'mysqldump';   
    }
}

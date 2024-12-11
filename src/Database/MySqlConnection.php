<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\MySqlBuilder;
use Exceedone\Exment\Database\Query\Processors\MySqlProcessor;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Illuminate\Database\Grammar;
use Illuminate\Database\MySqlConnection as BaseConnection;

class MySqlConnection extends BaseConnection implements ConnectionInterface
{
    use ConnectionTrait;

    protected static $isContainsColumnStatistics = null;

    /**
     * Get a schema builder instance for the connection.
     *
     * @return MySqlBuilder
     */
    public function getSchemaBuilder()
    {
        /** @phpstan-ignore-next-line Call to function is_null() with Illuminate\Database\Schema\Grammars\Grammar will always evaluate to false. */
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MySqlBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return Grammar|SchemaGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar());
    }

    /**
     * Get the default query grammar instance.
     *
     * @return Grammar|QueryGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
    }

    /**
     * Get the default post processor instance.
     *
     * @return MySqlProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MySqlProcessor();
    }


    /**
     * dumpDatabase mysqldump for backup table definition or table data.
     *
     * @param string $tempDir backup target table (default:null)
     * @param string $table
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
        $ls_output = shell_exec($mysqldump . ' --help');
        if (strpos($ls_output, '--set-gtid-purged') !== false) {
            $set_gtid = ' --set-gtid-purged=OFF';
        } else {
            $set_gtid = '';
        }
        $command = sprintf(
            '%s %s %s --no-tablespaces -h %s -u %s --password=%s -P %s',
            $mysqldump,
            $column_statistics,
            $set_gtid,
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
        if (!is_null(static::$isContainsColumnStatistics)) {
            return static::$isContainsColumnStatistics;
        }
        $mysqldump = static::getMysqlDumpPath();
        $command = sprintf(
            '%s --help',
            $mysqldump
        );
        exec($command, $output);

        static::$isContainsColumnStatistics = collect($output)->contains(function ($o) {
            return strpos($o, 'column-statistics') !== false;
        });

        return static::$isContainsColumnStatistics;
    }


    public function getDatabaseDriverName(): string
    {
        return 'MySQL';
    }


    /**
     * Check execute backup database
     *
     * @return bool
     * @throws BackupRestoreCheckException
     */
    public function checkBackup(): bool
    {
        $commands = [static::getMysqlDumpPath(), static::getMysqlPath()];
        foreach ($commands as $command) {
            $execCommand = "$command --version";
            exec($execCommand, $output, $return_var);

            if ($return_var != 0) {
                throw new BackupRestoreCheckException(exmtrans('backup.message.cmd_check_error', ['cmd' => $execCommand]));
            }
        }

        return true;
    }

    /**
     * Whether use unicode if search multiple column
     *
     * @return boolean
     */
    public function isUseUnicodeMultipleColumn(): bool
    {
        return false;
    }


    /**
     * Restore database
     *
     * @param string $tempDir dir path
     * @return void
     */
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
     * @param $tempDir
     * @param $table
     * @return void
     */
    protected function backupTable($tempDir, $table)
    {
        // create tsv file
        $file = new \SplFileObject(path_join($tempDir, $table . '.tsv'), 'w');
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


    /**
     * Restore database
     *
     * @param string $dirFullPath contains dir path
     * @return void
     */
    public function restoreDatabase($dirFullPath)
    {
        try {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // get table connect info
            $host = config('database.connections.mysql.host', '');
            $username = config('database.connections.mysql.username', '');
            $password = config('database.connections.mysql.password', '');
            $database = config('database.connections.mysql.database', '');
            $dbport = config('database.connections.mysql.port', '');

            $mysqlcmd = sprintf(
                '%s -h %s -u %s --password=%s -P %s %s',
                static::getMysqlPath(),
                $host,
                $username,
                $password,
                $dbport,
                $database
            );

            // restore table definition
            $def = path_join($dirFullPath, config('exment.backup_info.def_file'));
            if (\File::exists($def)) {
                $command = sprintf('%s < %s', $mysqlcmd, $def);
                exec($command);
                \File::delete($def);
            }

            // get insert sql file for each tables
            $files = array_filter(\File::files($dirFullPath), function ($file) {
                $filename = $file->getFilename();
                // ignore "view_" file. (Bug fix v3.6.7)
                return preg_match('/.+\.sql$/i', $filename) && !preg_match('/^view_.*/i', $filename);
            });

            foreach ($files as $file) {
                $command = sprintf('%s < %s', $mysqlcmd, $file->getRealPath());

                $table = $file->getBasename('.' . $file->getExtension());
                if (\Schema::hasTable($table)) {
                    \DB::table($table)->truncate();
                }

                exec($command);
            }
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }


    /**
     * insert table data from backup tsv files.
     *
     * @param string $dirFullPath restore file path
     */
    public function importTsv($dirFullPath)
    {
        // get tsv files in target folder
        $files = array_filter(\File::files($dirFullPath), function ($file) {
            return preg_match('/.+\.tsv$/i', $file);
        });

        try {
            \DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // load table data from tsv file
            foreach ($files as $file) {
                $table = $file->getBasename('.' . $file->getExtension());

                if (!\Schema::hasTable($table)) {
                    continue;
                }
                \DB::table($table)->truncate();

                $cmd = <<<__EOT__
                LOAD DATA local INFILE '%s'
                INTO TABLE %s
                CHARACTER SET 'UTF8'
                FIELDS TERMINATED BY '\t'
                OPTIONALLY ENCLOSED BY '\"'
                ESCAPED BY '\"'
                LINES TERMINATED BY '\\n'
                IGNORE 1 LINES
                SET created_at = nullif(created_at, '0000-00-00 00:00:00'),
                    updated_at = nullif(updated_at, '0000-00-00 00:00:00'),
                    deleted_at = nullif(deleted_at, '0000-00-00 00:00:00'),
                    created_user_id = nullif(created_user_id, 0),
                    updated_user_id = nullif(updated_user_id, 0),
                    deleted_user_id = nullif(deleted_user_id, 0),
                    parent_id = nullif(parent_id, 0)
__EOT__;
                $query = sprintf($cmd, addslashes($file->getPathName()), $table);
                $cnt = \DB::connection()->getpdo()->exec($query);

                //return $cnt;
            }
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }



    public function createView($viewName, $query)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        \DB::statement("
            CREATE OR REPLACE VIEW $viewName
            AS " . $query->toSql(), $query->getBindings());
    }

    public function dropView($viewName)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        \DB::statement("DROP VIEW IF EXISTS " . $viewName);
    }


    protected static function getMysqlPath()
    {
        return path_join_os(config('exment.backup_info.mysql_dir', ''), 'mysql');
    }

    protected static function getMysqlDumpPath()
    {
        return path_join_os(config('exment.backup_info.mysql_dir', ''), 'mysqldump');
    }
}

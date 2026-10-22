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

    // @phpstan-ignore-next-line
    protected static $isContainsColumnStatistics = null;

    /**
     * Get a schema builder instance for the connection.
     *
     * @return MySqlBuilder
     */
    public function getSchemaBuilder()
    {
        // @phpstan-ignore-next-line
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
        return new SchemaGrammar($this);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return Grammar|QueryGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
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
        $charset = config('database.connections.mysql.charset', 'utf8mb4');

        // mysqldump v8.0 or later, append "column-statistics=0" option
        // https://serverfault.com/questions/912162/mysqldump-throws-unknown-table-column-statistics-in-information-schema-1109
        $column_statistics = static::isContainsColumnStatistics() ? '--column-statistics=0' : '';

        $mysqldump = static::getMysqlDumpPath();
        $ls_output = shell_exec($mysqldump . ' --help');
        // @phpstan-ignore-next-line
        if (strpos($ls_output, '--set-gtid-purged') !== false) {
            $set_gtid = ' --set-gtid-purged=OFF';
        } else {
            $set_gtid = '';
        }
        $command = sprintf(
            '%s %s %s --no-tablespaces --default-character-set=%s -h %s -u %s --password=%s -P %s',
            $mysqldump,
            $column_statistics,
            $set_gtid,
            escapeshellarg($charset),
            escapeshellarg($host),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($dbport)
        );

        if ($table == null) {
            $file = path_join($tempDir, config('exment.backup_info.def_file', 'table_definition.sql'));
            $command = sprintf('%s -d %s > %s', $command, escapeshellarg($database), escapeshellarg($file));
        } else {
            $file = sprintf('%s.sql', path_join($tempDir, $table));
            $command = sprintf('%s -t %s %s > %s', $command, escapeshellarg($database), escapeshellarg($table), escapeshellarg($file));
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
    // @phpstan-ignore-next-line
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
            $charset = config('database.connections.mysql.charset', 'utf8mb4');

            $mysqlcmd = sprintf(
                '%s --default-character-set=%s -h %s -u %s --password=%s -P %s %s',
                static::getMysqlPath(),
                escapeshellarg($charset),
                escapeshellarg($host),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($dbport),
                escapeshellarg($database)
            );

            // restore table definition
            $def = path_join($dirFullPath, config('exment.backup_info.def_file'));
            if (\File::exists($def)) {
                $backupTables = $this->getDefinitionTables($def);

                $command = sprintf('%s < %s', $mysqlcmd, escapeshellarg($def));
                exec($command, $output, $return_var);
                \File::delete($def);

                // a definition that did not load leaves the database half replaced: keep the rest as it is
                if ($return_var == 0) {
                    $this->dropTablesNotInBackup($backupTables);
                }
            }

            // get insert sql file for each tables
            $files = array_filter(\File::files($dirFullPath), function ($file) {
                $filename = $file->getFilename();
                // ignore "view_" file. (Bug fix v3.6.7)
                return preg_match('/.+\.sql$/i', $filename) && !preg_match('/^view_.*/i', $filename);
            });

            foreach ($files as $file) {
                $command = sprintf('%s < %s', $mysqlcmd, escapeshellarg($file->getRealPath()));

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
     * Get the table names a backup table definition file creates.
     *
     * @param string $def table definition file path
     * @return array<string>
     */
    protected function getDefinitionTables($def): array
    {
        preg_match_all('/^CREATE TABLE (?:IF NOT EXISTS )?`((?:[^`]|``)+)`/m', \File::get($def), $matches);

        return array_map(function ($table) {
            return str_replace('``', '`', $table);
        }, $matches[1]);
    }

    /**
     * Drop the tables the backup does not have.
     *
     * Restore replaces only the tables in the backup, so a table made after the backup
     * (a newer version's table, a custom table's exm__ table) would keep the data of the
     * database being replaced while the restored migrations table no longer records it:
     * line_account_links would link LINE accounts to user ids of other users, and
     * exment:update would fail to create the table again.
     *
     * @param array<string> $backupTables
     * @return void
     */
    protected function dropTablesNotInBackup(array $backupTables)
    {
        foreach ($this->getTablesNotInBackup($backupTables, \Schema::getTableListing()) as $table) {
            \DB::statement('DROP TABLE IF EXISTS ' . $this->getQueryGrammar()->wrap($table));
        }
    }

    /**
     * Get the tables of the database the backup does not have.
     *
     * @param array<string> $backupTables tables the backup table definition creates
     * @param array<string> $tables tables the database has
     * @return array<string>
     */
    protected function getTablesNotInBackup(array $backupTables, array $tables): array
    {
        $backupTables = array_map('strtolower', $backupTables);

        // not a dump of an Exment database (empty or cut short): it cannot tell what to keep
        if (!in_array('custom_tables', $backupTables, true) || !in_array('migrations', $backupTables, true)) {
            return [];
        }

        return array_values(array_filter($tables, function ($table) use ($backupTables) {
            return !in_array(strtolower($table), $backupTables, true);
        }));
    }


    /**
     * insert table data from backup tsv files.
     *
     * @param string $dirFullPath restore file path
     */
    // @phpstan-ignore-next-line
    public function importTsv($dirFullPath)
    {
        // get tsv files in target folder
        /** @phpstan-ignore-next-line */
        $files = array_filter(\File::files($dirFullPath), function ($file) {
            return preg_match('/.+\.tsv$/i', $file);
        });

        // the tsv is written from the utf8mb4 connection: load it with the same charset.
        // 'UTF8' means utf8mb3, which turns every 4-byte character (emoji, rare kanji) into "?"
        $charset = config('database.connections.mysql.charset', 'utf8mb4');

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
                CHARACTER SET '%s'
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
                $query = sprintf($cmd, addslashes($file->getPathName()), $table, $charset);
                $cnt = \DB::connection()->getpdo()->exec($query);

                //return $cnt;
            }
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }



    // @phpstan-ignore-next-line
    public function createView($viewName, $query)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        \DB::statement("
            CREATE OR REPLACE VIEW $viewName
            AS " . $query->toSql(), $query->getBindings());
    }

    // @phpstan-ignore-next-line
    public function dropView($viewName)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        \DB::statement("DROP VIEW IF EXISTS " . $viewName);
    }


    // @phpstan-ignore-next-line
    protected static function getMysqlPath()
    {
        $path = path_join_os(config('exment.backup_info.mysql_dir', ''), 'mysql');
        // Quote path if it contains spaces (Windows compatibility)
        return (strpos($path, ' ') !== false) ? '"' . $path . '"' : $path;
    }

    // @phpstan-ignore-next-line
    protected static function getMysqlDumpPath()
    {
        $path = path_join_os(config('exment.backup_info.mysql_dir', ''), 'mysqldump');
        // Quote path if it contains spaces (Windows compatibility)
        return (strpos($path, ' ') !== false) ? '"' . $path . '"' : $path;
    }
}

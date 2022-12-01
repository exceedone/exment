<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\PostgresGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\PostgresGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\PostgresBuilder;
use Exceedone\Exment\Database\Query\Processors\PostgresProcessor;
use Illuminate\Database\PostgresConnection as BaseConnection;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;

class PostgresConnection extends BaseConnection implements ConnectionInterface
{
    use ConnectionTrait;
    
    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new PostgresBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return SchemaGrammar
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
     * @return PostgresProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new PostgresProcessor;
    }


    public function getDatabaseDriverName() : string
    {
        return 'PostgreSQL';
    }

    /**
     * Is enable execute backup driver
     *
     * @return bool
     */
    public function isEnableBackup() : bool
    {
        return false;
    }

    /**
     * Check postgresql
     *
     * @return bool
     */
    public function isPostgres()
    {
        return true;
    }

    /**
     * Check execute backup database
     *
     * @return bool
     * @throws BackupRestoreCheckException
     */
    public function checkBackup() : bool
    {
        $commands = [static::getPgDumpPath(), static::getPsqlPath()];
        foreach ($commands as $command) {
            $execCommand = '"' . $command . '" --version';
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
    public function isUseUnicodeMultipleColumn() : bool
    {
        return true;
    }

    /**
     * Whether update sequence used for column default value
     *
     * @return boolean
     */
    public function isUpdateDefaultSequence() : bool
    {
        return true;
    }

    public function backupDatabase($tempDir)
    {
        // export database
        $this->dumpDatabase($tempDir);
    }
    
    /**
     * Restore database
     *
     * @param string $dirFullPath contains dir path
     * @return void
     */
    public function restoreDatabase($dirFullPath)
    {
        // get table connect info
        $host = config('database.connections.pgsql.host', '');
        $username = config('database.connections.pgsql.username', '');
        $password = config('database.connections.pgsql.password', '');
        $database = config('database.connections.pgsql.database', '');
        $dbport = config('database.connections.pgsql.port', '');

        // restore database file
        $file = path_join($dirFullPath, config('exment.backup_info.def_file'));

        $command = sprintf(
            '"%s" -h %s -U %s -f %s -p %s -d %s',
            static::getPsqlPath(),
            $host,
            $username,
            $file,
            $dbport,
            $database
        );

        if (\File::exists($file)) {
            putenv("PGPASSWORD={$password}");
            exec($command);
            putenv("PGPASSWORD=");
            \File::delete($file);
        }
    }

    /**
     * insert table data from backup tsv files.
     *
     * @param string $dirFullPath restore file path
     */
    public function importTsv($dirFullPath)
    {
    }

    
    public function createView($viewName, $query)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        $sql = "CREATE OR REPLACE VIEW $viewName AS " . $query->toSql();

        ///// maybe sql server cannot replace bindings... so replace
        foreach ($query->getBindings() as $binding) {
            $sql = preg_replace('/\?/', \Exment::wrapValue($binding), $sql, 1);
        }

        \DB::statement($sql);
    }


    public function dropView($viewName)
    {
        $viewName = $this->getQueryGrammar()->wrapTable($viewName);
        \DB::statement("DROP VIEW IF EXISTS " . $viewName);
    }

    protected static function getPgDumpPath()
    {
        return path_join_os(config('exment.backup_info.postgres_dir', ''), 'pg_dump');
    }

    protected static function getPsqlPath()
    {
        return path_join_os(config('exment.backup_info.postgres_dir', ''), 'psql');
    }
    
    /**
     * dumpDatabase pg_dump for backup table definition or table data.
     *
     * @param string $tempDir backup target table (default:null)
     * @param string $table
     * @return void
     */
    protected function dumpDatabase($tempDir)
    {
        // get table connect info
        $host = config('database.connections.pgsql.host', '');
        $username = config('database.connections.pgsql.username', '');
        $password = config('database.connections.pgsql.password', '');
        $database = config('database.connections.pgsql.database', '');
        $dbport = config('database.connections.pgsql.port', '');
        $file = path_join($tempDir, config('exment.backup_info.def_file', 'table_definition.sql'));

        $pg_dump = static::getPgDumpPath();
        $command = sprintf(
            '"%s" -h %s -U %s -f %s -p %s -c --if-exists %s',
            $pg_dump,
            $host,
            $username,
            $file,
            $dbport,
            $database
        );

        putenv("PGPASSWORD={$password}");
        exec($command);
        putenv("PGPASSWORD=");
    }
}

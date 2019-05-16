<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\SqlServerGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\SqlServerGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\SqlServerBuilder;
use Exceedone\Exment\Database\Query\Processors\SqlServerProcessor;
use Illuminate\Database\SqlServerConnection as BaseConnection;

class SqlServerConnection extends BaseConnection
{
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

        return new SqlServerBuilder($this);
    }

    public function backupDatabase($tempDir)
    {
        // get table connect info
        $host = config('database.connections.sqlsrv.host', '');
        $username = config('database.connections.sqlsrv.username', '');
        $password = config('database.connections.sqlsrv.password', '');
        $dbport = config('database.connections.sqlsrv.port', '');
        $database = config('database.connections.sqlsrv.database', '');
        
        $hostPort = $host . (isset($dbport) ? ','.$dbport : '');

        $file = path_join($tempDir, 'table_definition.bak');
            
        // cannot execute this
        $sqlcmd = config('exment.backup_info.sqlcmd_dir', '') . 'sqlcmd';
        $command = sprintf(
            '%s -S "%s" -U %s -P %s -Q "BACKUP DATABASE %s TO DISK = N\'%s\' WITH INIT"',
            $sqlcmd,
            $hostPort,
            $username,
            $password,
            $database,
            $file
        );

        $ret = exec($command);
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
     * @return SqlServerProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new SqlServerProcessor;
    }
}

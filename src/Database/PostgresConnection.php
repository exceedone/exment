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
        throw new BackupRestoreNotSupportedException(exmtrans('backup.message.not_support_driver', $this->getDatabaseDriverName()));
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
    }
    
    /**
     * Restore database
     *
     * @param string $dirFullPath contains dir path
     * @return void
     */
    public function restoreDatabase($dirFullPath)
    {
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
}

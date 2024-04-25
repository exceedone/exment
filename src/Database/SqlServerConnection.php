<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\SqlServerGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\SqlServerGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\SqlServerBuilder;
use Exceedone\Exment\Database\Query\Processors\SqlServerProcessor;
use Illuminate\Database\Grammar;
use Illuminate\Database\SqlServerConnection as BaseConnection;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;

class SqlServerConnection extends BaseConnection implements ConnectionInterface
{
    use ConnectionTrait;

    /**
     * Get a schema builder instance for the connection.
     *
     * @return SqlServerBuilder
     */
    public function getSchemaBuilder()
    {
        /** @phpstan-ignore-next-line Call to function is_null() with Illuminate\Database\Schema\Grammars\Grammar will always evaluate to false. */
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SqlServerBuilder($this);
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
     * @return SqlServerProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new SqlServerProcessor();
    }


    public function getDatabaseDriverName(): string
    {
        return 'SQL Server';
    }

    /**
     * Check execute backup database
     *
     * @return bool
     * @throws BackupRestoreCheckException
     */
    public function checkBackup(): bool
    {
        throw new BackupRestoreNotSupportedException(exmtrans('backup.message.not_support_driver', $this->getDatabaseDriverName()));
    }

    /**
     * Whether use unicode if search multiple column
     *
     * @return boolean
     */
    public function isUseUnicodeMultipleColumn(): bool
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
        $sql = "CREATE OR ALTER VIEW $viewName AS " . $query->toSql();

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

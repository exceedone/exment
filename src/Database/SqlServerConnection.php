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

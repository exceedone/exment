<?php

namespace Exceedone\Exment\Database;

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
     * @return MySqlGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar);
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

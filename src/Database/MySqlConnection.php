<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\MySqlBuilder;
use Exceedone\Exment\Database\Query\Processors\MySqlProcessor;
use Illuminate\Database\MySqlConnection as BaseConnection;

class MySqlConnection extends BaseConnection
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
}

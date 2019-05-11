<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\MySqlBuilder;
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
}

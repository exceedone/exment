<?php

namespace Exceedone\Exment\Database;

use Exceedone\Exment\Database\Query\Grammars\MariaDBGrammar as QueryGrammar;
use Exceedone\Exment\Database\Schema\Grammars\MariaDBGrammar as SchemaGrammar;
use Exceedone\Exment\Database\Schema\MariaDBBuilder;
use Exceedone\Exment\Database\Query\Processors\MariaDBProcessor;
use Illuminate\Database\Grammar;

class MariaDBConnection extends MySqlConnection
{
    use ConnectionTrait;

    /**
     * Get a schema builder instance for the connection.
     *
     * @return MariaDBBuilder
     */
    public function getSchemaBuilder()
    {
        /** @phpstan-ignore-next-line Call to function is_null() with Illuminate\Database\Schema\Grammars\Grammar will always evaluate to false. */
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new MariaDBBuilder($this);
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
     * @return MariaDBProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MariaDBProcessor();
    }


    public function getDatabaseDriverName(): string
    {
        return 'MariaDB';
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
}

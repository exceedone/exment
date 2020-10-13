<?php

namespace Exceedone\Exment\Database;

trait ConnectionTrait
{
    /**
     * Get a new query builder instance.
     *
     * @return \Exceedone\Exment\Database\Query\ExtendedBuilder
     */
    public function query()
    {
        return new \Exceedone\Exment\Database\Query\ExtendedBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Get database version.
     *
     * @return void
     */
    public function getVersion()
    {
        return $this->getSchemaBuilder()->getVersion();
    }

    /**
     * Check mariadb
     *
     * @return bool
     */
    public function isMariaDB()
    {
        return $this->getSchemaBuilder()->isMariaDB();
    }

    /**
     * Check SqlServer
     *
     * @return bool
     */
    public function isSqlServer()
    {
        return $this->getSchemaBuilder()->isSqlServer();
    }

    public function canConnection()
    {
        try {
            $this->getSchemaBuilder()->getVersion();
            return true;
        } catch (\Exception $ex) {
            return false;
        }
    }
}

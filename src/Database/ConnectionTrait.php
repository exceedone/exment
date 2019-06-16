<?php

namespace Exceedone\Exment\Database;

trait ConnectionTrait
{
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
     * @return void
     */
    public function isMariaDB()
    {
        return $this->getSchemaBuilder()->isMariaDB();
    }

    public function canConnection()
    {
        try {
            $this->getSchemaBuilder()->getTableListing();
            return true;
        } catch (\Exception $ex) {
            return false;
        }
    }
}

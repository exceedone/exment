<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Services\LogManagement\LogConnection;

/**
 * A model of a log table: on the connection of the logs when one is set (ISS02905, LogConnection).
 */
trait UsesLogConnection
{
    /**
     * @return string|null
     */
    public function getConnectionName()
    {
        return LogConnection::name() ?? parent::getConnectionName();
    }
}

<?php

namespace Exceedone\Exment\Database\Connectors;

use Illuminate\Database\Connectors\MySqlConnector;
use Exceedone\Exment\Database\MariaDBConnection;

class MariaDBConnectionFactory extends \Illuminate\Database\Connectors\ConnectionFactory
{
    // @phpstan-ignore-next-line
    public function createConnector(array $config)
    {
        return new MySqlConnector();
    }

    // @phpstan-ignore-next-line
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        return new MariaDBConnection($connection, $database, $prefix, $config);
    }
}

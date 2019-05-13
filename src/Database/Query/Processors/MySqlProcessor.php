<?php

namespace Exceedone\Exment\Database\Query\Processors;

use Illuminate\Database\Query\Processors\MySqlProcessor as BaseMySqlProcessor;

class MySqlProcessor extends BaseMySqlProcessor
{
    /**
     * Process the results of a table listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processTableListing($results)
    {
        return array_map(function ($result) {
            return ((object) $result)->table_name;
        }, $results);
    }
}

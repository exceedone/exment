<?php

namespace Exceedone\Exment\ExmentExporters;

interface ExmentExporterInterface
{
    /**
     * Export data from grid.
     *
     * @return mixed
     */
    public function export($table, $search_enabled_columns, $get_template);
}

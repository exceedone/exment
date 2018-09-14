<?php

namespace Exceedone\Exment\ExmentImporters;

interface ExmentImporterInterface
{
    /**
     * Export data from grid.
     *
     * @return mixed
     */
    public function import($request);
}

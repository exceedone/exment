<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

abstract class ProviderBase
{
    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct()
    {
    }

    /**
     * get data object
     */
    abstract public function getDataObject($data, $options = []);
}

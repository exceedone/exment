<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

abstract class ProviderBase
{
    /**
     * get data object
     */
    abstract public function getDataObject($data, $options = []);
}

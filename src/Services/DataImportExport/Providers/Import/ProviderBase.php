<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

abstract class ProviderBase
{
    /**
     * get data object
     */
    abstract public function getDataObject($data, $options = []);

    /**
     * validate Import Data.
     * @return array please return 2 columns array. 1st success data array, 2nd error array.
     */
    abstract public function validateImportData($dataObjects);

    /**
     * import data
     */
    abstract public function importdata($data);
}

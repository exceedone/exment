<?php

namespace Exceedone\Exment\Services\DataImportExport\Services;

interface ServiceInterface
{
    /**
     * get output data list
     */
    public function datalist();

    /**
     * get file base name
     */
    public function filebasename();
}

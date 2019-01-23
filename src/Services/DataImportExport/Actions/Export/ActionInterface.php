<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

interface ActionInterface
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

<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

interface ActionInterface
{
    /**
     * get output data list
     */
    // @phpstan-ignore-next-line
    public function datalist();

    /**
     * get file base name
     */
    // @phpstan-ignore-next-line
    public function filebasename();
}

<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

interface ActionInterface
{
    public function filterDatalist($datalist);

    public function import($datalist, $options = []);
}

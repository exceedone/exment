<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

interface ActionInterface
{
    // @phpstan-ignore-next-line
    public function filterDatalist($datalist);

    // @phpstan-ignore-next-line
    public function import($datalist, $options = []);
}

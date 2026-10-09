<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

class SummaryProvider extends ViewProvider
{
    // @phpstan-ignore-next-line
    protected function appendBodyItemOptions(array $options, $index)
    {
        $options['summary'] = true;
        $options['summary_index'] = $index;
        return $options;
    }
}

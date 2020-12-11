<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Export;

class SummaryProvider extends ViewProvider
{
    protected function appendBodyItemOptions(array $options, $index)
    {
        $options['summary'] = true;
        $options['summary_index'] = $index;
        return $options;
    }
}

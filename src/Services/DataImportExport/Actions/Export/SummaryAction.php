<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class SummaryAction extends ViewAction
{
    protected function getProvider()
    {
        return new Export\SummaryProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            'grid' => $this->grid
        ]);
    }
}

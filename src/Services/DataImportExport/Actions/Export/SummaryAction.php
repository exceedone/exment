<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;

class SummaryAction extends CustomTableAction
{
    public function datalist()
    {
        $providers = [];

        // get default data
        $providers[] = new Export\SummaryProvider([
            'custom_table' => $this->custom_table,
            'grid' => $this->grid
        ]);
        
        $datalist = [];
        foreach ($providers as $provider) {
            if (!$provider->isOutput()) {
                continue;
            }

            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        }

        return $datalist;
    }
}

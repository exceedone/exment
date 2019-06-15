<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class SummaryAction extends CustomTableAction
{
    protected $custom_view;
    
    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');

        $this->custom_view = array_get($args, 'custom_view');

        $this->grid = array_get($args, 'grid');
    }

    public function datalist()
    {
        $providers = [];

        // get default data
        $providers[] = new Export\SummaryProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
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

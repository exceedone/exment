<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

class ViewAction extends CustomTableAction
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

        $providers[] = $this->getProvider();

        $datalist = [];
        foreach ($providers as $provider) {
            if (!$provider->isOutput()) {
                continue;
            }

            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
            $this->count .= $provider->getCount();
        }

        return $datalist;
    }


    protected function getProvider()
    {
        return new Export\ViewProvider([
            'custom_table' => $this->custom_table,
            'custom_view' => $this->custom_view,
            'grid' => $this->grid
        ]);
    }
}

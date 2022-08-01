<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;

/**
 * Export using Paginate
 */
class PaginateAction extends ExportActionBase implements ActionInterface
{
    /**
     * Whether is get all data
     *
     * @var boolean
     */
    protected $isAll = false;

    /**
     * Widget grid
     *
     */
    protected $grid;

    /**
     * file headers array.
     *
     * @var array
     */
    protected $headers;

    /**
     * file name.
     *
     * @var string
     */
    protected $filename;

    public function __construct($args = [])
    {
        $this->isAll = array_get($args, 'isAll');
        $this->grid = array_get($args, 'grid');
        $this->headers = array_get($args, 'headers');
        $this->filename = array_get($args, 'filename');
    }

    public function datalist()
    {
        $providers = [];

        // get default data
        $providers[] = new Export\PaginateProvider([
            'isAll' => $this->isAll,
            'grid' => $this->grid,
            'headers' => $this->headers,
            'filename' => $this->filename,
        ]);

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

    /**
     * Execute output
     *
     * @return void
     */
    public function execute()
    {
    }

    /**
     * Get file name
     *
     * @return string
     */
    public function filebasename()
    {
        return '';
    }
}

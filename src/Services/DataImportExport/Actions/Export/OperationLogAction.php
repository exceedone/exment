<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Exceedone\Exment\Services\DataImportExport\Formats\SpOut;
use Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

class OperationLogAction extends ExportActionBase implements ActionInterface
{
    /**
     * laravel-admin grid
     */
    protected $grid;

    public function __construct($args = [])
    {
        $this->grid = array_get($args, 'grid');
    }

    public function datalist()
    {
        $provider = new Export\OperationLogProvider([
            'grid' => $this->grid
        ]);

        $datalist = [];
        $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        $this->count = $provider->getCount();

        return $datalist;
    }

    public function filebasename()
    {
        return 'operation_log';
    }

    /**
     * Get format class(SpOut\Xlsx, PhpSpreadSheet\Csv, ...)
     *
     * @param string|null $format
     * @param string $library
     * @return FormatBase
     */
    public function getFormatClass(?string $format, string $library): FormatBase
    {
        switch ($format) {
            case 'excel':
            case 'xlsx':
                return new SpOut\Xlsx();
            default:
                return new SpOut\Csv();
        }
    }
}

<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Grid\Exporters\AbstractExporter;

class ExportService extends AbstractExporter
{
    use ImportExportTrait;

    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    public function __construct($args = []){
        $this->format = static::getFormat($args);
        
        if (array_has($args, 'grid')) {
            $this->setGrid(array_get($args, 'grid'));
        }
    }

    public static function getService($args = []){
        $model = new self($args);

        return $model;
    }
    
    /**
     * execute export
     */
    public function export()
    {
        $datalist = $this->action->datalist();

        $files = $this->format
            ->datalist($datalist)
            ->filebasename($this->action->filebasename())
            ->createFile();
        
        $response = $this->format->createResponse($files);
        $response->send();
        exit;
    }
}

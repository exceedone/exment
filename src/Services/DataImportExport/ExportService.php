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
        
        $response = $this->createResponse($files);
        $response->send();
        exit;
    }
    
    protected function createResponse($files){
        return response()->stream(function () use ($files) {
            $files[0]['writer']->save('php://output');
        }, 200, $this->getDefaultHeaders());
    }

    protected function getDefaultHeaders(){
        $filename = $this->format->getFileName();
        return [
            'Content-Type'        => 'application/force-download',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
    }

}

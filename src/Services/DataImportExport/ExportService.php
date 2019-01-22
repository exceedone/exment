<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Grid\Exporters\AbstractExporter;

abstract class ExportService extends AbstractExporter
{
    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    protected $format;

    public function __construct($args = []){
        $format = app('request')->input('format');
        switch ($format) {
            case 'excel':
            case 'xlsx':
                $this->format = new Formats\Xlsx();
                break;
            default:
                $this->format = new Formats\Csv();
                break;
        }
        
        if (array_has($args, 'grid')) {
            $this->setGrid(array_get($args, 'grid'));
        }
    }

    public static function getService($args = []){
        return new Services\TableService($args);
    }
    
    /**
     * execute export
     */
    public function export()
    {
        $datalist = $this->datalist();

        $files = $this->format
            ->datalist($datalist)
            ->filebasename($this->filebasename())
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

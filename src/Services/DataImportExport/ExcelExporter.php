<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelExporter extends DataExporterBase
{
    protected function createWriter($spreadsheet){
        return IOFactory::createWriter($spreadsheet, 'Xlsx');
    }

    protected function createResponse($files){
        return response()->stream(function () use ($files) {
            $files[0]['writer']->save('php://output');
        }, 200, $this->getDefaultHeaders());
    }

    protected function getFileName(){
        return $this->custom_table->table_view_name.date('YmdHis').".xlsx";
    }
    
    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip(){
        // check relations
        return false;
    }

}

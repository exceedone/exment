<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use PhpOffice\PhpSpreadsheet\IOFactory;

class Xlsx extends FormatBase
{
    public function getFileName(){
        return $this->filebasename.date('YmdHis'). ".xlsx";
    }

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     */
    protected function isOutputAsZip(){
        return false;
    }
    
    protected function createWriter($spreadsheet){
        return IOFactory::createWriter($spreadsheet, 'Xlsx');
    }
}

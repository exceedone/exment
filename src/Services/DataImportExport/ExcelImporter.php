<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImporter extends DataImporterBase
{
    protected $accept_extension = 'xlsx';

    protected function getDataTable($request){
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($request->file('custom_table_file')->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        // read cell
        //$sheet = $reader->getSheet();
        return getDataFromSheet($sheet);
    }    
}

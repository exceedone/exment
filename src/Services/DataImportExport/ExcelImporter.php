<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImporter extends DataImporterBase
{
    protected $accept_extension = 'xlsx';

    /**
     * get data table list. contains self table, and relations (if contains)
     */
    protected function getDataTable($request)
    {
        // get file
        if(is_string($request)){
            $path = $request;
        }else{
            $file = $request->file('custom_table_file');
            $path = $file->getRealPath();
        }
        
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        $datalist = [];
        // first, get custom_table sheet list
        $sheet = $spreadsheet->getSheetByName($this->custom_table->table_name);
        if(isset($sheet)){
            $datalist[$this->custom_table->table_name] = [
                'custom_table' => $this->custom_table,
                'data' => getDataFromSheet($sheet),
            ];
        }

        // second, get relation data(if exists)
        foreach($this->relations as $relation){
            $sheetName = $relation->getSheetName();
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if(!isset($sheet)){
                continue;
            }
            $datalist[$sheetName] = [
                'custom_table' => $relation->child_custom_table,
                'relation' => $relation,
                'data' => getDataFromSheet($sheet),
            ];
        }

        // read cell
        return $datalist;
    }
}

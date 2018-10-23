<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Maatwebsite\Excel\Facades\Excel;

class ExcelImporter extends DataImporterBase
{
    protected $accept_extension = 'xlsx';

    protected function getDataTable($request){
        $reader = Excel::load($request->file('custom_table_file')->getRealPath());
        if ($reader == null)
        {
            throw new \Exception('error.');
        }

        // set config
        config(['excel.import.heading' => false]); // heading false. read first row

        // read cell
        $sheet = $reader->getSheet();
        $data = [];
        foreach ($reader->all() as $index => $cells)
        {
            // get data 
            $d = $cells->all();
            // if not found, break
            if(collect($d)->filter(function($v){
                return !is_nullorempty($v);
            })->count() == 0){
                break;
            }
            $data[] = $d;
        }
        
        return $data;
    }    
}

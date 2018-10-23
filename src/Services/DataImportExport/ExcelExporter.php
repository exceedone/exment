<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Maatwebsite\Excel\Facades\Excel;

class ExcelExporter extends DataExporterBase
{
    /**
     * execute export
     */
    public function export()
    {
        $filename = $this->table->table_view_name.date('YmdHis');
        // get output table
        $outputs = $this->getDataTable();

        // set config
        config(['excel.export.sheets.strictNullComparison' => true]); // Even if val is 0, outout cell

        // create excel
        Excel::create($filename, function($excel) use($outputs) {
            // create sheet
            $excel->sheet($this->table->table_name, function($sheet) use($outputs) {
                // add from array
                $sheet->fromArray($outputs, null, 'A1', false, false);
            });

        })->export('xlsx');
    }
}

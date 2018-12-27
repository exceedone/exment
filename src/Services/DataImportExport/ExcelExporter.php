<?php

namespace Exceedone\Exment\Services\DataImportExport;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelExporter extends DataExporterBase
{
    // /**
    //  * execute export
    //  */
    // public function export()
    // {
    //     $filename = $this->table->table_view_name.date('YmdHis').".xlsx";
    //     // get output table
    //     $outputs = $this->getDataTable();

    //     // create excel
    //     $spreadsheet = new Spreadsheet();
    //     $sheet = $spreadsheet->getActiveSheet()->setTitle($this->table->table_name);
    //     $sheet->fromArray($outputs, null, 'A1', false, false);

    //     // set autosize
    //     if (count($outputs) > 0) {
    //         $counts = count($outputs[0]);
    //         for ($i = 0; $i < $counts; $i++) {
    //             $sheet->getColumnDimension(getCellAlphabet($i + 1))->setAutoSize(true);
    //         }
    //     }

    //     $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

    //     $res_headers = [
    //         'Content-Type'        => 'application/vnd.ms-excel',
    //         'Content-Disposition' => "attachment; filename=\"$filename\"",
    //     ];
    //     response()->stream(function () use ($writer) {
    //         $writer->save('php://output');
    //     }, 200, $res_headers)->send();
    //     exit;
    // }

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
}

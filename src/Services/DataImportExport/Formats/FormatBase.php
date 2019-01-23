<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

abstract class FormatBase
{
    protected $datalist;
    protected $filebasename;
    protected $accept_extension = '*';

    public function datalist($datalist = []){
        if(!func_num_args()){
            return $this->datalist;
        }
        
        $this->datalist = $datalist;
        
        return $this;
    }

    public function filebasename($filebasename = []){
        if(!func_num_args()){
            return $this->filebasename;
        }
        
        $this->filebasename = $filebasename;
        
        return $this;
    }

    public function accept_extension(){
        return $this->accept_extension;
    }

    /**
     * create file
     * 1 sheet - 1 table data
     */
    public function createFile()
    {
        // define writers. if zip, set as array.
        $files = [];
        // create excel
        $spreadsheet = new Spreadsheet();
        foreach ($this->datalist as $index => $data) {
            $sheet_name = array_get($data, 'name');
            $outputs = array_get($data, 'outputs');

            $sheet = new Worksheet($spreadsheet, $sheet_name);
            $sheet->fromArray($outputs, null, 'A1', false, false);

            // set autosize
            if (count($outputs) > 0) {
                $counts = count($outputs[0]);
                for ($i = 0; $i < $counts; $i++) {
                    $sheet->getColumnDimension(getCellAlphabet($i + 1))->setAutoSize(true);
                }
            }

            if($this->isOutputAsZip()){
                $spreadsheet->addSheet($sheet);
                $spreadsheet->removeSheetByIndex(0);
                $files[] = [
                    'name' => $sheet_name,
                    'writer' => $this->createWriter($spreadsheet)
                ];
                $spreadsheet = new Spreadsheet();
            }else{
                $spreadsheet->addSheet($sheet);
            }
        }

        if(!$this->isOutputAsZip()){
            $spreadsheet->removeSheetByIndex(0);
            $files[] = [
                'name' => $sheet_name,
                'writer' => $this->createWriter($spreadsheet)
            ];
        }
        return $files;
    }

    abstract public function createResponse($files);
    abstract protected function getDefaultHeaders();
}

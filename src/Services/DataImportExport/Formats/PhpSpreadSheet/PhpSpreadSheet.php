<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Exceedone\Exment\Services\DataImportExport\Formats;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell;

abstract class PhpSpreadSheet extends FormatBase
{
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
        $sheet_name = null;
        foreach ($this->datalist as $index => $data) {
            $sheet_name = array_get($data, 'name');
            $outputs = array_get($data, 'outputs');

            $sheet = new Worksheet($spreadsheet, $sheet_name);
            $sheet->fromArray($outputs, null, 'A1', false);

            // set autosize
            if (count($outputs) > 0) {
                // convert folmula cell to string
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Cell\Coordinate::columnIndexFromString($highestColumn);
                for ($row = 1; $row <= $highestRow; ++$row) {
                    for ($col = 1; $col <= $highestColumnIndex; ++$col) {
                        $cell = $sheet->getCellByColumnAndRow($col, $row);
                        if (strpos($cell->getValue(), '=') === 0) {
                            $cell->setDataType(Cell\DataType::TYPE_STRING);
                        }
                    }
                }
                $counts = count($outputs[0]);
                for ($i = 0; $i < $counts; $i++) {
                    $sheet->getColumnDimension(getCellAlphabet($i + 1))->setAutoSize(true);
                }
            }

            if ($this->isOutputAsZip()) {
                $spreadsheet->addSheet($sheet);
                $spreadsheet->removeSheetByIndex(0);
                $files[] = [
                    'name' => $sheet_name,
                    'spreadsheet' => $spreadsheet
                ];
                $spreadsheet = new Spreadsheet();
            } else {
                $spreadsheet->addSheet($sheet);
            }
        }

        if (!$this->isOutputAsZip()) {
            $spreadsheet->removeSheetByIndex(0);
            $files[] = [
                'name' => $sheet_name,
                'spreadsheet' => $spreadsheet
            ];
        }
        return $files;
    }
}

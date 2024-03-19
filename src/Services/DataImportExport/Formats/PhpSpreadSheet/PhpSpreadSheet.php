<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
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
        $outputPath = null;
        foreach ($this->datalist as $index => $data) {
            $sheet_name = array_get($data, 'name');
            $outputs = array_get($data, 'outputs');

            // if output as zip, change file name.
            if ($this->isOutputAsZip()) {
                $outputPath = $this->getTmpFilePath($this->getRealFileName($sheet_name));
            }
            // if not output as zip, output file name is download file name.
            else {
                $outputPath = $this->getTmpFilePath($this->getFileName());
            }

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
                        if (strpos_ex($cell->getValue(), '=') === 0) {
                            $cell->setDataType(Cell\DataType::TYPE_STRING);
                        }
                        // set percent last, set as string
                        elseif (rstrpos($cell->getValue(), '%') === 0) {
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

                // save file
                $writer = $this->createWriter($spreadsheet);
                $writer->save($outputPath);

                $files[] = [
                    'name' => $this->getRealFileName($sheet_name),
                    'path' => $outputPath,
                ];

                // recreate Spreadsheet
                $spreadsheet = new Spreadsheet();
            } else {
                $spreadsheet->addSheet($sheet);
            }
        }

        if (!$this->isOutputAsZip()) {
            $spreadsheet->removeSheetByIndex(0);

            $writer = $this->createWriter($spreadsheet);
            $writer->save($outputPath);
            $files[] = [
                'name' => $this->getFileName(),
                'path' => $outputPath,
            ];
        }

        // create download file
        $this->createDownloadFile($files);

        return $files;
    }

    /**
     * Get Data from Excel sheet
     * @param Worksheet $sheet
     * @param bool $keyvalue
     * @param bool $isGetMerge
     * @param array $options
     * @return array
     */
    public function getDataFromSheet($sheet, bool $keyvalue = false, bool $isGetMerge = false, array $options = []): array
    {
        $data = [];
        foreach ($sheet->getRowIterator() as $row_no => $row) {
            if (!$this->isReadSheetRow($row_no, $options)) {
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // This loops through all cells,
            $cells = [];
            foreach ($cellIterator as $column_no => $cell) {
                $value = $this->getCellValue($cell, $sheet, $isGetMerge);

                // if keyvalue, set array as key value
                if ($keyvalue) {
                    $key = $this->getCellValue($column_no."1", $sheet, $isGetMerge);
                    $cells[$key] = mbTrim($value);
                }
                // if false, set as array
                else {
                    $cells[] = mbTrim($value);
                }
            }
            if (collect($cells)->filter(function ($v) {
                return !is_nullorempty($v);
            })->count() == 0) {
                break;
            }
            $data[] = $cells;
        }

        return $data;
    }


    /**
     * get cell value
     *
     * @param string|Cell\Cell $cell
     * @param Worksheet $sheet
     * @param boolean $isGetMerge
     * @return mixed
     */
    public function getCellValue($cell, $sheet, $isGetMerge = false)
    {
        if (is_string($cell)) {
            $cell = $sheet->getCell($cell);
        }

        // if merge cell, get from master cell
        if ($isGetMerge && $cell->isInMergeRange()) {
            $mergeRange = $cell->getMergeRange();
            $cell = $sheet->getCell(explode(":", $mergeRange)[0]);
        }

        $value = $cell->getCalculatedValue();
        // is datetime, convert to date string
        if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell) && is_numeric($value)) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
            if (floatval($value) < 1) {
                $value = $date->format('H:i:s');
            } else {
                $value = ctype_digit(strval($value)) ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
            }
        }
        // if rich text, set plain value
        elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $value = $value->getPlainText();
        }
        return $value;
    }
}

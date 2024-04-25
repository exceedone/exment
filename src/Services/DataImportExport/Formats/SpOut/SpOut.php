<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\SpOut;

use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Box\Spout\Common\Entity\Cell;
use Box\Spout\Reader\SheetInterface;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class SpOut extends FormatBase
{
    /**
     * create file
     * 1 sheet - 1 table data
     */
    public function createFile()
    {
        // define writers. if zip, set as array.
        $files = [];
        $sheet_name = null;
        $outputPath = null;

        // create excel
        $writer = $this->createWriter(null);
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

            // If spout, have to set tmp file path.
            $writer->openToFile($outputPath);

            $outputs = array_map(function ($output) {
                return array_map(function ($o) {
                    if ($o instanceof \Carbon\Carbon) {
                        $o = $o->__toString();
                    }
                    return WriterEntityFactory::createCell($o);
                }, $output);
            }, $outputs);

            // set sheet name and create
            if ($writer instanceof \Box\Spout\Writer\XLSX\Writer) {
                if ($index == 0) {
                    $sheet = $writer->getCurrentSheet();
                } else {
                    $sheet = $writer->addNewSheetAndMakeItCurrent();
                }
                $sheet->setName($sheet_name);
            }

            foreach ($outputs as $output) {
                $writer->addRow(WriterEntityFactory::createRow($output));
            }

            // if output as zip, save file, and new writer
            if ($this->isOutputAsZip()) {
                $writer->close();
                $files[] = [
                    'name' => $this->getRealFileName($sheet_name),
                    'path' => $outputPath,
                ];
                $writer = $this->createWriter(null);
            } else {
            }
        }

        if (!$this->isOutputAsZip()) {
            $writer->close();
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
     * Get Data from excel sheet
     * @param SheetInterface $sheet
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

            $cellItems = $row->getCells();
            $cells = [];
            foreach ($cellItems as $column_no => $cell) {
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
     * @param \Box\Spout\Common\Entity\Cell|string $cell
     * @param Worksheet $sheet
     * @param bool $isGetMerge
     * @return null
     */
    public function getCellValue($cell, $sheet, $isGetMerge = false)
    {
        if (is_string($cell)) {
            $cell = $sheet->getCell($cell);
        }

        // Cannot get merge cell
        // if merge cell, get from master cell
        // if ($isGetMerge && $cell->isInMergeRange()) {
        //     $mergeRange = $cell->getMergeRange();
        //     $cell = $sheet->getCell(explode(":", $mergeRange)[0]);
        // }

        // If SpOut, already Calculated.
        // $value = $cell->getCalculatedValue();
        $value = $cell->getValue();
        $type = $cell->getType();

        // is datetime, convert to date string
        if ($type === Cell::TYPE_DATE && isset($value)) {
            // check hmi
            if ($value->format('H') > 0 || $value->format('i') > 0 || $value->format('s') > 0) {
                $value = $value->format('Y-m-d H:i:s');
            } else {
                $value = $value->format('Y-m-d');
            }
        }
        // // if rich text, set plain value
        // elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
        //     $value = $value->getPlainText();
        // }

        if (is_nullorempty($value)) {
            $value = null;
        }
        return $value;
    }
}

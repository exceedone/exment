<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\SpOut;

use Exceedone\Exment\Services\DataImportExport\Formats\XlsxTrait;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Box\Spout\Reader\ReaderAbstract;

class Xlsx extends SpOut
{
    use XlsxTrait;

    protected $accept_extension = 'xlsx';

    /**
     * get data table list. contains self table, and relations (if contains)
     */
    public function getDataTable($request, array $options = [])
    {
        $options = $this->getDataOptions($options);
        return $this->_getData($request, function (ReaderAbstract $reader) use ($options) {
            // if over row size, return number
            if (boolval($options['checkCount'])) {
                if (($count = $this->getRowCount($reader)) > (config('exment.import_max_row_count', 1000) + 2)) {
                    return $count;
                }
            }

            // get all data
            $datalist = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = $sheet->getName();
                $datalist[$sheetName] = $this->getDataFromSheet($sheet, false, true, $options);
            }

            return $datalist;
        });
    }


    protected function _getData($request, $callback)
    {
        list($path, $extension, $originalName, $file) = $this->getFileInfo($request);

        $reader = $this->createReader();
        $reader->open($path);
        try {
            return $callback($reader);
        } finally {
        }
    }


    /**
     * Get all sheet's row count
     *
     * @param ReaderAbstract $reader
     * @return int
     */
    protected function getRowCount(ReaderAbstract $reader): int
    {
        $count = 0;

        // get data count
        // cannot row count directry, so loop
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheetName = $sheet->getName();
            foreach ($sheet->getRowIterator() as $row) {
                $count++;
            }
        }

        return $count;
    }


    /**
     * @return \Box\Spout\Writer\XLSX\Writer
     */
    protected function createWriter($spreadsheet)
    {
        return WriterEntityFactory::createXLSXWriter();
    }


    /**
     * @return \Box\Spout\Reader\XLSX\Reader
     */
    protected function createReader()
    {
        return ReaderEntityFactory::createXLSXReader();
    }
}

<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Exceedone\Exment\Services\DataImportExport\Formats\XlsxTrait;

class Xlsx extends PhpSpreadSheet
{
    use XlsxTrait;

    // @phpstan-ignore-next-line
    protected $accept_extension = 'xlsx';

    /**
     * get data table list. contains self table, and relations (if contains)
     */
    // @phpstan-ignore-next-line
    public function getDataTable($request, array $options = [])
    {
        $options = $this->getDataOptions($options);
        return $this->_getData($request, function ($spreadsheet) use ($options) {
            // if over row size, return number
            if (boolval($options['checkCount'])) {
                if (($count = $this->getRowCount($spreadsheet)) > (config('exment.import_max_row_count', 1000) + 2)) {
                    return $count;
                }
            }

            // get all data
            $datalist = [];
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                $datalist[$sheetName] = $this->getDataFromSheet($sheet, false, true, $options);
            }

            return $datalist;
        });
    }


    // @phpstan-ignore-next-line
    protected function _getData($request, $callback)
    {
        // get file
        list($path, $extension, $originalName, $file) = $this->getFileInfo($request);

        $reader = $this->createReader();
        $spreadsheet = $reader->load($path);
        try {
            return $callback($spreadsheet);
        } finally {
            // close workbook and release memory
            $spreadsheet->disconnectWorksheets();
            $spreadsheet->garbageCollect();
            unset($spreadsheet, $reader);
        }
    }

    /**
     * Get all sheet's row count
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @return int
     */
    protected function getRowCount($spreadsheet): int
    {
        $count = 0;

        // get data count
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            $count += intval($sheet->getHighestRow());
        }

        return $count;
    }


    // @phpstan-ignore-next-line
    protected function createWriter($spreadsheet)
    {
        return IOFactory::createWriter($spreadsheet, 'Xlsx');
    }

    // @phpstan-ignore-next-line
    protected function createReader()
    {
        return IOFactory::createReader('Xlsx');
    }
}

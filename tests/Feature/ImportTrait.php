<?php

namespace Exceedone\Exment\Tests\Feature;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

trait ImportTrait
{
    protected function _getCsvArray($file)
    {
        $original_locale = setlocale(LC_CTYPE, 0);

        // set C locale
        if (0 === strpos(PHP_OS, 'WIN')) {
            setlocale(LC_CTYPE, 'C');
        }

        /** @var Csv $reader */
        $reader = IOFactory::createReader('Csv');
        $reader->setInputEncoding('UTF-8');
        $reader->setDelimiter(",");
        $spreadsheet = $reader->load($file);
        $array = $spreadsheet->getActiveSheet()->toArray();

        // revert to original locale
        setlocale(LC_CTYPE, $original_locale);

        return $array;
    }

    protected function _getXlsxArray($file_path)
    {
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($file_path);
        try {
            // get all data
            $datalist = [];
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $sheet = $spreadsheet->getSheetByName($sheetName);
                $datalist[$sheetName] = getDataFromSheet($sheet, false, true);
            }

            return $datalist;
        } finally {
            // close workbook and release memory
            $spreadsheet->disconnectWorksheets();
            $spreadsheet->garbageCollect();
            unset($spreadsheet, $reader);
        }
    }
}

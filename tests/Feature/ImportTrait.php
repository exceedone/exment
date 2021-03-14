<?php

namespace Exceedone\Exment\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;

trait ImportTrait
{
    protected function _getCsvArray($file)
    {
        $original_locale = setlocale(LC_CTYPE, 0);

        // set C locale
        if (0 === strpos(PHP_OS, 'WIN')) {
            setlocale(LC_CTYPE, 'C');
        }

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

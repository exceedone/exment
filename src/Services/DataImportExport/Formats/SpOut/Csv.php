<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats\SpOut;

use Exceedone\Exment\Services\DataImportExport\Formats\CsvTrait;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;

class Csv extends SpOut
{
    use CsvTrait;

    protected $accept_extension = 'csv,zip';


    /**
     * Get all csv's row count
     *
     * @param string|array|\Illuminate\Support\Collection $files
     * @return int
     */
    protected function getRowCount($files): int
    {
        $count = 0;
        if (is_string($files)) {
            $files = [$files];
        }

        // get data count
        foreach ($files as $file) {
            $reader = $this->createReader();
            $reader->setEncoding('UTF-8');
            $reader->setFieldDelimiter(",");
            $reader->open($file);

            // cannot row count directry, so loop
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = $sheet->getName();
                foreach ($sheet->getRowIterator() as $row) {
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function getCsvArray($file, array $options = [])
    {
        $original_locale = setlocale(LC_CTYPE, 0);

        // set C locale
        if (\Exment::isWindows()) {
            setlocale(LC_CTYPE, 'C');
        }

        $reader = $this->createReader();
        $reader->setEncoding('UTF-8');
        $reader->setFieldDelimiter(",");
        $reader->open($file);

        $array = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $array = $this->getDataFromSheet($sheet, false, false, $options);
            // csv is only get first sheet.
            break;
        }

        // revert to original locale
        setlocale(LC_CTYPE, $original_locale);

        return $array;
    }


    /**
     * @return \Box\Spout\Writer\CSV\Writer
     */
    protected function createWriter($spreadsheet)
    {
        return WriterEntityFactory::createCSVWriter();
    }


    /**
     * @return \Box\Spout\Reader\CSV\Reader
     */
    protected function createReader()
    {
        return ReaderEntityFactory::createCSVReader();
    }
}

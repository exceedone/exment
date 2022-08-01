<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

abstract class ProviderBase
{
    /**
     * Whether this row reads.
     *
     * @param integer $row_no from 1 array's count
     * @param array $options
     * @return boolean
     */
    protected function isReadRow(int $row_no, array $options = []): bool
    {
        // get options
        list($start, $end) = [
            array_get($options, 'row_start'),
            array_get($options, 'row_end'),
        ];

        // if has start option and $data_row_no is under $start,
        // this row has to skip, so return false;
        if (!is_null($start) && $row_no < $start) {
            return false;
        }

        // if has end option and $data_row_no is under $start,
        // this row has to skip, so return false;
        if (!is_null($end) && $row_no > $end) {
            return false;
        }

        return true;
    }


    /**
     * get data object
     */
    abstract public function getDataObject($data, $options = []);

    /**
     * validate Import Data.
     * @return array please return 2 columns array. 1st success data array, 2nd error array.
     */
    abstract public function validateImportData($dataObjects);

    /**
     * import data
     */
    abstract public function importdata($data);
}

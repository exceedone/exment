<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Exceedone\Exment\Services\DataImportExport\Formats\SpOut;
use Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet;

abstract class ExportActionBase
{
    /**
     * data's count
     *
     * @var int|string
     */
    protected $count = 0;

    public function getCount()
    {
        return $this->count;
    }

    /**
     * Get format class(SpOut\Xlsx, PhpSpreadSheet\Csv, ...)
     * @param string|null $format
     * @param string $library
     * @return FormatBase
     */
    public function getFormatClass(?string $format, string $library): FormatBase
    {
        return FormatBase::getFormatClass($format, $library, true);
    }
}

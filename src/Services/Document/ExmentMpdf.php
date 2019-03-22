<?php
namespace Exceedone\Exment\Services\Document;

use PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf;

class ExmentMpdf extends Mpdf
{
    /**
     * Gets the implementation of external PDF library that should be used.
     *
     * @param array $config Configuration array
     *
     * @return \Mpdf\Mpdf implementation
     */
    protected function createExternalWriterInstance($config)
    {
        $locale = \App::getLocale();
        $config['mode'] = $locale == 'ja'? $locale.'+aCJK': $locale;
        return new \Mpdf\Mpdf($config);
    }
}
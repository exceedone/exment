<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use Exceedone\Exment\Enums\ExportImportLibrary;

abstract class FormatBase
{
    protected $datalist;
    protected $filebasename;
    protected $accept_extension = '*';

    public function datalist($datalist = [])
    {
        if (!func_num_args()) {
            return $this->datalist;
        }
        
        $this->datalist = $datalist;
        
        return $this;
    }

    public function filebasename($filebasename = [])
    {
        if (!func_num_args()) {
            return $this->filebasename;
        }
        
        $this->filebasename = $filebasename;
        
        return $this;
    }

    public function accept_extension()
    {
        return $this->accept_extension;
    }


    /**
     * Get options for getdatatable or count
     *
     * @param array $options
     * @return array
     */
    public function getDataOptions(array $options)
    {
        return array_merge([
            'checkCount' => true, // whether checking count
            'page' => null, // if set, getting target page count
            'take' => null, // if set, taking data count
        ], $options);
    }

    
    public static function getFormatClass(?string $format, $library = null) : FormatBase
    {
        switch ($format) {
            case 'excel':
            case 'xlsx':
                return new PhpSpreadSheet\Xlsx();
                //return isMatchString($library, ExportImportLibrary::SP_OUT) ? new SpOut\Xlsx() : new PhpSpreadSheet\Xlsx();
            default:
                return new PhpSpreadSheet\Csv();
                //return isMatchString($library, ExportImportLibrary::SP_OUT) ? new SpOut\Csv() : new PhpSpreadSheet\Csv();
        }

        return new PhpSpreadSheet\Xlsx();
    }



    
    /**
     * create file
     * 1 sheet - 1 table data
     */
    abstract public function createFile();
    abstract public function createResponse($files);
    abstract public function getFormat();
    abstract protected function getDefaultHeaders();

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     *
     * @return boolean
     */
    abstract protected function isOutputAsZip();
}

<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use Exceedone\Exment\Enums\ExportImportLibrary;

abstract class FormatBase
{
    protected $datalist;
    protected $filebasename;
    protected $downloadFilePath;
    protected $output_aszip;
    
    protected $extension = '*';
    protected $accept_extension = '*';
    
    /**
     * File saved tmp directory path
     *
     * @var string
     */
    protected $tmpdir;


    public function __destruct(){
        $this->deleteTmpDirectory();
    }

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
     * is output aszip
     *
     * @param bool $output_aszip is false, always output as files
     * @return $this
     */
    public function output_aszip(bool $output_aszip)
    {
        $this->output_aszip = $output_aszip;
        return $this;
    }



    public function sendResponse()
    {
        $response = response()->download($this->getDownloadFilePath(), $this->getFileName(), $this->getDefaultHeaders());
        $response->send();

        // remove tmp directory
        $this->deleteTmpDirectory();
        exit;
    }
    
    
    public function saveAsFile($dirpath)
    {
        // move file tmp directory to $dirpath
        collect(\File::files($this->tmpdir()))->each(function($file) use($dirpath){
            $filename = pathinfo($file, PATHINFO_BASENAME);
            \File::move($file, path_join($dirpath, $filename));
        });

        // remove tmp directory
        $this->deleteTmpDirectory();
    }

    /**
     * Get real file name, contains extension.
     * Even if csv and contains 2 files, return as csv, not zip.
     *
     * @param string $name
     * @return string
     */
    public function getRealFileName(string $name) : string
    {
        return $name . '.' . $this->getFormat();
    }


    /**
     * Get DownloadFilePath
     *
     * @return string
     */
    protected function getDownloadFilePath() : string
    {
        return $this->downloadFilePath;
    }

    /**
     * Get temp path
     *
     * @return string
     */
    protected function tmpdir() : string
    {
        if(!$this->tmpdir){
            $this->tmpdir = \Exment::getTmpFolderPath('data');
        }

        return $this->tmpdir;
    }

    /**
     * Get temp file pathpath
     *
     * @return string
     */
    protected function getTmpFilePath($fileName) : string
    {
        return path_join($this->tmpdir(), $fileName);
    }


    protected function getDefaultHeaders()
    {
        $filename = $this->getFileName();
        return [
            'Content-Type'        => 'application/force-download',
            'Content-disposition' => "attachment; filename*=UTF-8''". rawurlencode($filename),
        ];
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

    
    public static function getFormatClass(?string $format, bool $isExport) : FormatBase
    {
        if($isExport){
            $library = isMatchString(config('exment.export_library'), 'SP_OUT') ? ExportImportLibrary::SP_OUT : ExportImportLibrary::PHP_SPREAD_SHEET;
        }
        else{
            $library = isMatchString(config('exment.import_library'), 'PHP_SPREAD_SHEET') ? ExportImportLibrary::PHP_SPREAD_SHEET : ExportImportLibrary::SP_OUT;
        }
        
        switch ($format) {
            case 'excel':
            case 'xlsx':
                //return new PhpSpreadSheet\Xlsx();
                return isMatchString($library, ExportImportLibrary::SP_OUT) ? new SpOut\Xlsx() : new PhpSpreadSheet\Xlsx();
            default:
                //return new PhpSpreadSheet\Csv();
                return isMatchString($library, ExportImportLibrary::SP_OUT) ? new SpOut\Csv() : new PhpSpreadSheet\Csv();
        }

        return new PhpSpreadSheet\Xlsx();
    }


    /**
     * Create download file and return tmp download file path
     *
     * @param array $files
     * @return string
     */
    protected function createDownloadFile(array $files) : string
    {
        // save as csv
        if (count($files) == 1) {
            $this->downloadFilePath = $files[0]['path'];
            return $this->downloadFilePath;
        }
        // save as zip
        else {
            $tmpdir = $this->tmpdir();

            $zip = new \ZipArchive();
            $zipfilename = short_uuid().'.zip';
            $zipfillpath = path_join($tmpdir, $zipfilename);
            $res = $zip->open($zipfillpath, \ZipArchive::CREATE);
            
            foreach ($files as $f) {
                $zip->addFile($f['path'], $f['name']);
            }
            $zip->close();

            $this->downloadFilePath = $zipfillpath;
            return $zipfillpath;
        }
    }


    protected function deleteTmpDirectory(){
        if($this->tmpdir && \File::exists($this->tmpdir)){
            try{
                \File::deleteDirectory($this->tmpdir);
            }
            catch(\Exception $ex){
            }
        }
    }
    


    /**
     * create file
     * 1 sheet - 1 table data
     */
    abstract public function createFile();
    

    abstract public function getFormat();
    abstract public function getFileName();

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     *
     * @return boolean
     */
    abstract protected function isOutputAsZip();
}

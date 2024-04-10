<?php

namespace Exceedone\Exment\Services\DataImportExport\Formats;

use Exceedone\Exment\Enums\ExportImportLibrary;
use Illuminate\Http\Request;

abstract class FormatBase
{
    protected $datalist;
    protected $filebasename;
    protected $downloadFilePath;
    protected $output_aszip;

    /**
     * Whether call background
     *
     * @var bool
     */
    protected $isBackground = false;

    protected $extension = '*';
    protected $accept_extension = '*';

    /**
     * File saved tmp directory path
     *
     * @var string
     */
    protected $tmpdir;


    public function __destruct()
    {
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

    public function background()
    {
        $this->isBackground = true;
        return $this;
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
        collect(\File::files($this->tmpdir()))->each(function ($file) use ($dirpath) {
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
    public function getRealFileName(string $name): string
    {
        return $name . '.' . $this->getFormat();
    }

    /**
     * Get file name for download
     *
     * @return string
     */
    public function getFileName(): string
    {
        $fileName = $this->filebasename;

        if (!$this->isBackground) {
            $fileName .= date('YmdHis');
        }
        $fileName .= ".";

        if ($this->isOutputAsZip()) {
            $fileName .= "zip";
        } else {
            $fileName .= $this->getFormat();
        }
        return $fileName;
    }


    /**
     * Get DownloadFilePath
     *
     * @return string
     */
    protected function getDownloadFilePath(): string
    {
        return $this->downloadFilePath;
    }

    /**
     * Get temp path
     *
     * @return string
     */
    protected function tmpdir(): string
    {
        if (!$this->tmpdir) {
            $this->tmpdir = \Exment::getTmpFolderPath('data');
        }

        return $this->tmpdir;
    }

    /**
     * Get temp file pathpath
     *
     * @return string
     */
    protected function getTmpFilePath($fileName): string
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

    /**
     * Get format class(SpOut\Xlsx, PhpSpreadSheet\Csv, ...)
     *
     * @param string|null $format
     * @param string $library
     * @param bool $isExport
     * @return FormatBase
     */
    public static function getFormatClass(?string $format, string $library, bool $isExport): FormatBase
    {
        if ($isExport) {
            if (!is_null($config = config('exment.export_library'))) {
                $library = isMatchString($config, 'SP_OUT') ? ExportImportLibrary::SP_OUT : ExportImportLibrary::PHP_SPREAD_SHEET;
            }
        } else {
            if (!is_null($config = config('exment.import_library'))) {
                $library = isMatchString($config, 'PHP_SPREAD_SHEET') ? ExportImportLibrary::PHP_SPREAD_SHEET : ExportImportLibrary::SP_OUT;
            }
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
        // unreachable statement
//        return new PhpSpreadSheet\Xlsx();
    }


    /**
     * Create download file and return tmp download file path
     *
     * @param array $files
     * @return string
     */
    protected function createDownloadFile(array $files): string
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

    /**
     * Get file, path, name, etc
     *
     * @param Request|\SplFileInfo|string $target
     * @return array [$path, $extension, $originalName];
     */
    protected function getFileInfo($target): array
    {
        // get file
        if ($target instanceof Request) {
            $file = $target->file('custom_table_file');
            $path = $file->getRealPath();
            $extension = $file->extension();
            $originalName = $file->getClientOriginalName();
        } elseif ($target instanceof \SplFileInfo) {
            $path = $target->getPathName();
            $extension = pathinfo($path)['extension'];
            $originalName = pathinfo($path, PATHINFO_BASENAME);
        } else {
            $path = $target;
            $extension = pathinfo($path)['extension'];
            $originalName = pathinfo($path, PATHINFO_BASENAME);
        }

        return [$path, $extension, $originalName, $file ?? null];
    }

    /**
     * delete tmp directory. Calls after response or file save.
     *
     * @return void
     */
    protected function deleteTmpDirectory()
    {
        if ($this->tmpdir && \File::exists($this->tmpdir)) {
            try {
                \File::deleteDirectory($this->tmpdir);
            } catch (\Exception $ex) {
            }
        }
    }


    /**
     * Whether this sheet row reads.
     *
     * @param integer $sheet_row_no
     * @param array $options
     * @return boolean
     */
    protected function isReadSheetRow(int $sheet_row_no, array $options = []): bool
    {
        // get options
        list($skip_excel_row_no) = [
            array_get($options, 'skip_excel_row_no'),
        ];

        // if has skip_excel_row_no option and $sheet_row_no is under $header_row,
        // this row has to skip, so return false;
        if (!is_null($skip_excel_row_no) && $sheet_row_no <= $skip_excel_row_no) {
            return false;
        }

        return true;
    }



    /**
     * create file
     * 1 sheet - 1 table data
     */
    abstract public function createFile();


    abstract public function getFormat();

    /**
     * whether this out is as zip.
     * This table is parent and contains relation 1:n or n:n.
     *
     * @return boolean
     */
    abstract protected function isOutputAsZip();
    abstract protected function createWriter($spreadsheet);
    abstract protected function createReader();
}

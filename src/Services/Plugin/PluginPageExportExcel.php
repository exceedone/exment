<?php

namespace Exceedone\Exment\Services\Plugin;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Plugin (Page) base class for excel
 *
 * Create 2023/06/02 by m.hagane
 */
abstract class PluginPageExportExcel extends PluginPageBase
{
    /**
     *
     *
     */
    protected $file;

    public function __construct($plugin)
    {
	parent::__construct($plugin);
       // $this->file = $file;
    }

    /**
     * Initialize excel
     *
     * @param string $templateFileName If read template file, set filename
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    protected function initializeExcel($templateFileName = null)
    {
        $reader = IOFactory::createReader('Xlsx');

        if (isset($templateFileName)) {
            $reader = IOFactory::createReader('Xlsx');

            $filePath = $this->plugin->getFullPath($templateFileName);
            if (!\File::exists($filePath)) {
                //TODO:template file not found
                throw new \Exception();
            }

            $spreadsheet = $reader->load($filePath);
            return $spreadsheet;
        }

        return new Spreadsheet();
    }

    /**
     * Get result
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @return string tmp file name.
     */
    protected function getExcelResult($spreadsheet)
    {
        // output excel
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);
        //$writer->setPreCalculateFormulas(true);
        $writer->save($this->getTmpFullPath());

        return $this->getTmpFullPath();
    }

    /**
     * get Directory full path from root
     * @return string File path
     */
    public function getTmpFullPath()
    {
        if (isset($this->tmpFullPath)) {
            return $this->tmpFullPath;
        }

        $file = make_uuid();
        $this->tmpFullPath = getFullpath($file, Define::DISKNAME_ADMIN_TMP);
        return $this->tmpFullPath;
    }
}

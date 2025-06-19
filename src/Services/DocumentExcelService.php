<?php
/*
 */

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomValue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Exceedone\Exment\Storage\Disk\AdminDiskService;

class DocumentExcelService
{
    /**
     *
     */
    protected $baseInfo;
    protected $templateFileFullPath;
    protected $outputfilename;
    protected $filename;
    protected $uniqueFileName;

    /**
     * Image setted disk services
     *
     * @var array
     */
    protected $diskServies = [];

    /**
     * CustomValue
     *
     * @var CustomValue
     */
    protected $model;

    /**
     * Before saving callback
     *
     * @var \Closure|null
     */
    protected $savingCallback;

    /**
     * after called callback
     *
     * @var \Closure|null
     */
    protected $calledCallback;

    /**
     * construct
     *
     * @param CustomValue $model output's model
     * @param string $templateFileFullPath template's file full path
     * @param string $outputfilename Output's file name. *Not contains extension. If want to output 'test.xlsx', set 'test'.*
     */
    public function __construct($model, $templateFileFullPath, $outputfilename)
    {
        $this->model = $model;
        $this->templateFileFullPath = $templateFileFullPath;
        $this->outputfilename = $outputfilename;
    }

    /**
     * Create PDF
     * @return boolean
     */
    public function makeExcel()
    {
        //Excel::selectSheetsByIndex(0)->load($this->filename, function($reader) {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($this->templateFileFullPath);

            if ($this->calledCallback) {
                call_user_func($this->calledCallback, $spreadsheet);
            }

            // output all sheets
            $showGridlines = [];
            $sheetCount = $spreadsheet->getSheetCount();
            for ($i = 0; $i < $sheetCount; $i++) {
                $sheet = $spreadsheet->getSheet($i);
                $showGridlines[] = $sheet->getShowGridlines();
                // output table
                $this->lfTable($sheet);

                // outputvalue
                $this->lfValue($sheet);
            }

            // output excel
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->setIncludeCharts(true);
            //$writer->setPreCalculateFormulas(true);
            $writer->save($this->getFullPathTmp());

            // re-load and save again. (Because cannot calc formula)
            $reader = IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($this->getFullPathTmp());

            $sheetCount = $spreadsheet->getSheetCount();
            for ($i = 0; $i < $sheetCount; $i++) {
                $sheet = $spreadsheet->getSheet($i);
                $sheet->setShowGridlines($showGridlines[$i]);
            }

            if ($this->savingCallback) {
                call_user_func($this->savingCallback, $spreadsheet);
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $this->saveFile($writer);

            // remove tmpfile
            \File::delete($this->getAdminTmpFullPath());
            \File::delete($this->getFullPathTmp());

            return true;
        } finally {
            // Delete tmp directory
            foreach ($this->diskServies as $diskService) {
                $diskService->deleteTmpDirectory();
            }
        }
    }

    /**
     * Write Table
     */
    protected function lfTable($sheet)
    {
        // first time, define loop value
        $loops = [];
        $this->callbackSheetCell($sheet, function ($cell, $val, $matches) use (&$loops, $sheet) {
            // $matches - Results of searching with regular expressions ${XXXXX}
            // $matches[0] - ${XXXXX} , $matches[1] - XXXXX
            foreach ($matches[1] as $index => $m) {
                // split ":"
                $splits = explode(":", $m);
                if (count($splits) < 3) {
                    continue;
                }
                list($format_key, $table_name, $column_name) = $splits;

                // not $format_key is not "loop", continue
                if (!in_array($format_key, ['loop', 'loop-item'])) {
                    continue;
                }

                // set loops array
                if (!array_has($loops, $table_name)) {
                    $loops[$table_name] = [
                        'start' => null,
                        'end' => null,
                        'columns' => [],
                    ];
                }

                $cell_column = $cell->getColumn();
                // if loop, get (start or end) row no.
                if ($format_key == 'loop') {
                    $loops[$table_name][$column_name] = $cell->getRow();
                }
                // if loop-item, get outputing column no.
                elseif ($format_key == 'loop-item') {
                    $key = "$table_name.columns.$cell_column";
                    if (!array_has($loops, $key)) {
                        array_set($loops, $key, [
                            'text' => null,
                            'formats' => [],
                        ]);
                    }
                    $loops[$table_name]['columns'][$cell_column]['text'] = getCellValue($cell, $sheet);
                    $loops[$table_name]['columns'][$cell_column]['formats'][] = [
                        'format_text' => $matches[0][$index],
                        'column_name' => '${value:'. $column_name . '}',
                    ];
                }
            }
        });
        if (count($loops) == 0) {
            return;
        }

        // looping item
        foreach ($loops as $table => $loop_item) {
            if (!array_has($loop_item, 'start')
                || !array_has($loop_item, 'columns')
            ) {
                continue;
            }
            if (!array_has($loop_item, 'end')) {
                $loop_item['end'] = intval($loop_item['start']) + 100;
            }
            // get children value
            $children = $this->model->getChildrenValues($table) ?? [];

            // get excel row using $loop_item['start']
            $row = intval(array_get($loop_item, 'start'));
            $end = intval(array_get($loop_item, 'end'));

            // looping $children
            foreach ($children as $child) {
                // loop columns
                foreach ($loop_item['columns'] as $cell_column => &$column_item) {
                    $text = $column_item['text'];
                    // loop formats
                    foreach ($column_item['formats'] as $format) {
                        // replace using format
                        $text = str_replace($format['format_text'], $this->getText(
                            $format['column_name'],
                            [],
                            $child
                        ), $text);
                    }
                    $sheet->setCellValue($cell_column . $row, $text);
                }

                $row = $row + 1;
                if ($row > $end) {
                    break;
                }
            }
        }
    }

    /**
     * Write default value
     */
    protected function lfValue($sheet)
    {
        // first time, define loop value
        $this->callbackSheetCell($sheet, function ($cell, $val, $matches) use ($sheet) {
            $text = $this->getText($val);
            $cellpos = $cell->getColumn() . $cell->getRow();
            if ($text instanceof Drawing) {
                $text->setCoordinates($cellpos);
                $text->setWorksheet($sheet);
                $sheet->setCellValue($cellpos, '');
            } else {
                $sheet->setCellValue($cellpos, $text);
            }
        });
    }

    protected function callbackSheetCell($sheet, $callback)
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cellValue = getCellValue($cell, $sheet) ?? null;

                if (is_nullorempty($cellValue)) {
                    continue;
                }
                // if match value
                preg_match_all('/'.Define::RULES_REGEX_VALUE_FORMAT.'/', $cellValue, $matches);
                if (count($matches) == 0) {
                    continue;
                }
                if (is_null($matches[1]) || count($matches[1]) == 0) {
                    continue;
                }
                $callback($cell, $cellValue, $matches);
            }
        }
    }

    /**
     * get output text from document item
     */
    protected function getText($text, $options = [], $model = null)
    {
        $options['disable_currency_symbol'] = true;
        $options['disable_number_format'] = true;
        $options['afterCallback'] = function ($text, $custom_value, $options) {
            return $this->replaceText($text, $options);
        };
        $options['matchBeforeCallbackForce'] = function ($length_array, $custom_value, $options, $matchOptions) {
            $key = $length_array[0];

            if ($key == 'value_image') {
                $length_array = array_slice($length_array, 1);
                $path = $custom_value->getValue(implode('.', $length_array), false, $matchOptions) ?? '';
                if (is_nullorempty($path)) {
                    return '';
                }
                if (is_list($path)) {
                    $path = collect($path)->first();
                }

                $drawing = $this->getImage($path, $matchOptions);
                return $drawing;
            }
        };
        return replaceTextFromFormat($text, $model?? $this->model, $options);
    }

    /**
     * replace text. ex.comma, &yen, etc...
     */
    protected function replaceText($text, $documentItem = [])
    {
        // add comma if number_format
        if (array_key_exists('number_format', $documentItem) && !str_contains($text, ',') && is_numeric($text)) {
            $text = number_format($text);
        }

        // replace <br/> or \r\n, \n, \r to new line
        $text = preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $text);
        // &yen; to
        $text = str_replace("&yen;", "¥", $text);

        return $text;
    }

    /**
     * get file name
     * @return string File name
     */
    public function getFileName()
    {
        if (!isset($this->filename)) {
            // get template file name
            $this->filename = $this->getText($this->outputfilename) ?? make_uuid();
        }
        return $this->filename.'.xlsx';
    }

    /**
     * get unique file name
     * @return string File name
     */
    public function getUniqueFileName()
    {
        if (!isset($this->uniqueFileName)) {
            $ext = '.xlsx';
            $this->uniqueFileName = make_uuid().$ext;
        }
        return $this->uniqueFileName;
    }

    /**
     * get File path after storage/admin.
     * @return string File path
     */
    public function getFilePath()
    {
        return path_join($this->getDirPath(), $this->getFileName());
    }

    /**
     * get Directory path after storage/admin.
     * @return string File path
     */
    public function getDirPath()
    {
        // create directory
        $dir_fullpath = getFullpath('document', config('admin.upload.disk'));
        \Exment::makeDirectory($dir_fullpath);
        //return getFullpath('document', config('admin.upload.disk'));
        return 'document';
    }

    /**
     * get full path from root, for downloading
     * @return string File path
     */
    public function getFullPath()
    {
        $filepath = path_join($this->getDirPath(), $this->getUniqueFileName());
        return getFullpath($filepath, Define::DISKNAME_ADMIN, true);
    }

    /**
     * get admin tmp Directory full path from root
     * @return string File path
     */
    protected function getAdminTmpFullPath()
    {
        $filepath = path_join($this->getDirPath(), $this->getUniqueFileName());
        return getFullpath($filepath, Define::DISKNAME_ADMIN_TMP, true);
    }

    /**
     * get (tmp saving) Directory full path from root
     * @return string File path
     */
    protected function getFullPathTmp()
    {
        $filepath = path_join($this->getDirPath(), $this->getFileName().'tmp');
        return getFullpath($filepath, Define::DISKNAME_ADMIN_TMP, true);
    }

    /**
     * Save admin_tmp and move admin
     *
     * @return void
     */
    protected function saveFile($writer)
    {
        // save file to local
        $tmpFullPath = $this->getAdminTmpFullPath();
        $writer->save($tmpFullPath);

        $file = path_join($this->getDirPath(), $this->getUniqueFileName());
        // copy admin_tmp to admin
        $stream = \Storage::disk(Define::DISKNAME_ADMIN_TMP)->readStream($file);
        \Storage::disk(Define::DISKNAME_ADMIN)->writeStream($file, $stream);

        try {
            fclose($stream);
        } catch (\Exception $ex) {
        }
    }


    /**
     * set image full
     *
     * @param string|null $path
     * @return Drawing|null
     */
    protected function getImage(?string $path, $matchOptions)
    {
        $diskService = new AdminDiskService($path);
        // sync from crowd.
        $diskService->syncFromDisk();

        $path = $diskService->localSyncDiskItem()->fileFullPath();
        if (!\File::exists($path)) {
            return null;
        }

        $width = array_get($matchOptions, 'width');
        $height = array_get($matchOptions, 'height');

        // create drawing object
        $drawing = new Drawing();
        $drawing->setPath($path);
        if (isset($width) && isset($height)) {
            $drawing->setResizeProportional(false);
            $drawing->setWidth($width);
            $drawing->setHeight($height);
        } elseif (isset($width)) {
            $drawing->setWidth($width);
        } elseif (isset($height)) {
            $drawing->setHeight($height);
        }

        $this->diskServies[] = $diskService;

        return $drawing;
    }

    /**
     * After called event
     *
     * @param \Closure $callback
     * @return $this
     */
    public function setCalledCallback(\Closure $callback)
    {
        $this->calledCallback = $callback;
        return $this;
    }

    /**
     * Before save event
     *
     * @param \Closure $callback
     * @return $this
     */
    public function setSavingCallback(\Closure $callback)
    {
        $this->savingCallback = $callback;
        return $this;
    }
}

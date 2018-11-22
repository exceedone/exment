<?php
/*
 * This file is part of the Order Pdf plugin
 *
 * Copyright (C) 2016 LOCKON CO.,LTD. All Rights Reserved.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Exceedone\Exment\Services;

use setasign\Fpdi;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class CreatePdfService.
 * Do export pdf function.
 */
class DocumentExcelService
{
    /**
     *
     */
    private $baseInfo;
    private $tempfilename;
    private $outputfilename;
    private $filename;

    private $model;
    /**
     * construct
     * @param Request $request
     * @param $document
     */
    public function __construct($model, $tempfilename, $outputfilename)
    {
        $this->model = $model;
        $this->tempfilename = $tempfilename;
        $this->outputfilename = $outputfilename;
    }

    /**
     * Create PDF
     * @return boolean
     */
    public function makeExcel()
    {
        //Excel::selectSheetsByIndex(0)->load($this->filename, function($reader) {
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($this->tempfilename);
        $sheet = $spreadsheet->getActiveSheet();

        // outputvalue
        $this->lfValue($sheet);

        // output table
        $this->lfTable($sheet);

        // output excel
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($this->getFullPath());

        return true;
    }

    /**
     * Write Table
     */
    protected function lfTable($sheet)
    {
        // first time, define loop value
        $loops = [];
        $this->callbackSheetCell($sheet, function ($cell, $val, $matches) use (&$loops) {
            foreach ($matches[1] as $m) {
                // split ":"
                $splits = explode(":", $m);
                if (count($splits) < 3) {
                    continue;
                }
                // not $splits[0] is not "loop", continue
                if (!in_array($splits[0], ['loop', 'loop-item'])) {
                    continue;
                }
            
                // set loops array
                if (!array_has($loops, $splits[1])) {
                    $loops[$splits[1]] = [];
                }
                if (!array_has($loops[$splits[1]], 'items')) {
                    $loops[$splits[1]]['items'] = [];
                }
                // if loop, get (start or end) row no.
                if ($splits[0] == 'loop') {
                    $loops[$splits[1]][$splits[2]] = $cell->getRow();
                }
                // if loop-item, get outputing column no.
                elseif ($splits[0] == 'loop-item') {
                    $loops[$splits[1]]['items'][$splits[2]] = $cell->getColumn();
                }

                // remove value
                $cell->setValue('');
            }
        });
        if (count($loops) == 0) {
            return;
        }

        // looping item
        foreach ($loops as $table => $loop_item) {
            if (!array_has($loop_item, 'start')
                || !array_has($loop_item, 'items')
            ) {
                continue;
            }
            if (!array_has($loop_item, 'end')) {
                $loop_item['end'] = intval($loop_item['start']) + 100;
            }
            // get children value
            $children = getChildrenValues($this->model, $table);

            // get excel row using $loop_item['start']
            $row = intval(array_get($loop_item, 'start'));
            $end = intval(array_get($loop_item, 'end'));

            // looping $children
            foreach ($children as $child) {
                // loop items
                foreach ($loop_item['items'] as $column_name => $sheet_column_no) {
                    // output sheet
                    $text = $this->replaceText($child->getValue($column_name, false), []);
                    $sheet->setCellValue($sheet_column_no . $row, $text);
                }

                $row++;
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
            $sheet->setCellValue($cell->getColumn() . $cell->getRow(), $text);
        });
    }

    protected function callbackSheetCell($sheet, $callback)
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cellValue = $cell->getValue() ?? null;

                if (is_nullorempty($cellValue)) {
                    continue;
                }
                // if match value
                preg_match_all('/\${(.*?)\}/', $cellValue, $matches);
                if (count($matches) == 0) {
                    continue;
                }

                // split ":"
                if (is_null($matches[1]) || count($matches[1]) == 0) {
                    continue;
                }

                // execute callback
                $callback($cell, $cellValue, $matches);
            }
        }
    }

    /**
     * get output text from document item
     */
    protected function getText($text, $documentItem = [])
    {
        // check string
        preg_match_all('/\${(.*?)\}/', $text, $matches);
        if (isset($matches)) {
            // loop for matches. because we want to get inner {}, loop $matches[1].
            for ($i = 0; $i < count($matches[1]); $i++) {
                try {
                    $match = strtolower($matches[1][$i]);
                
                    // get column
                    $length_array = explode(":", $match);
                    
                    if (in_array($length_array[0], ['loop', 'loop-item'])) {
                        continue;
                    }
                    ///// value
                    elseif ($length_array[0] == "value") {
                        // get value from model
                        if (count($length_array) <= 1) {
                            $str = '';
                        } else {
                            // get comma string from index 1.
                            $length_array = array_slice($length_array, 1);
                            $str = getValue($this->model, implode(',', $length_array), false, array_get($documentItem, 'format'));
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                    ///// sum
                    elseif ($length_array[0] == "sum") {
                        // get sum value from children model
                        if (count($length_array) <= 2) {
                            $str = '';
                        }
                        //else, getting value using cihldren
                        else {
                            // get children values
                            $children = getChildrenValues($this->model, $length_array[1]);
                            // looping
                            $sum = 0;
                            foreach ($children as $child) {
                                // get value
                                $sum += intval(str_replace(',', '', $child->getValue($length_array[2])));
                            }
                            $str = strval($sum);
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                    // base_info
                    elseif ($length_array[0] == "base_info") {
                        $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
                        // get value from model
                        if (count($length_array) <= 1) {
                            $str = '';
                        } else {
                            $str = getValue($base_info, $length_array[1], false, array_get($documentItem, 'format'));
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                    // suuid
                    elseif ($length_array[0] == "suuid") {
                        $text = str_replace($matches[0][$i], short_uuid(), $text);
                    }
                    // uuid
                    elseif ($length_array[0] == "uuid") {
                        $text = str_replace($matches[0][$i], make_uuid(), $text);
                    }
                    // ymdhms
                    elseif ($length_array[0] == "ymdhms") {
                        $text = str_replace($matches[0][$i], \Carbon\Carbon::now()->format('YmdHis'), $text);
                    }
                    // ymdhm
                    elseif ($length_array[0] == "ymdhm") {
                        $text = str_replace($matches[0][$i], \Carbon\Carbon::now()->format('YmdHi'), $text);
                    }
                    // ymd
                    elseif ($length_array[0] == "ymd") {
                        $text = str_replace($matches[0][$i], \Carbon\Carbon::now()->format('Ymd'), $text);
                    }
                } catch (Exception $e) {
                }
            }
        }

        return $this->replaceText($text, $documentItem);
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
        if (!\File::exists($dir_fullpath)) {
            \File::makeDirectory($dir_fullpath);
        }
        //return getFullpath('document', config('admin.upload.disk'));
        return 'document';
    }

    /**
     * get Directory full path from root
     * @return string File path
     */
    public function getFullPath()
    {
        $filepath = path_join($this->getDirPath(), $this->getFileName());
        return getFullpath($filepath, config('admin.upload.disk'));
    }
}

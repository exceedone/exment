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
    private $filename;

    private $model;
    /**
     * construct
     * @param Request $request
     * @param $document
     */
    public function __construct($model, $filename)
    {
        $this->model = $model;
        $this->filename = $filename;
    }

    /**
     * Create PDF
     * @return boolean
     */
    public function makeExcel()
    {
        //Excel::selectSheetsByIndex(0)->load($this->filename, function($reader) {
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($this->filename);
        $sheet = $spreadsheet->getActiveSheet();
        // output table
        $this->lfTable($sheet);

        // outputvalue
        $this->lfValue($sheet);

        // output excel 
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save(path_join($this->getDirPath(), $this->getFileNameWithExtension()));

        return true;
    }

    /**
     * get file name
     * @return string File name
     */
    public function getFileName()
    {
        // get document file name
        //$filename = $this->getText(array_get($this->documentInfo, 'filename'));
        if(!isset($filename)){
            $filename = make_uuid();
        }
        // set from document_type
        return $filename;
    }

    /**
     * get file name
     * @return string File name
     */
    public function getFileNameWithExtension()
    {
        return $this->getFileName().'.xlsx';
    }

    /**
     * get Directory path
     * @return string File path
     */
    public function getDirPath()
    {
        // set from document_type
        return storage_path(path_join('document'));
    }

    /**
     * Write Table
     */
    protected function lfTable($sheet)
    {
        // first time, define loop value
        $loops = [];
        $this->callbackSheetCell($sheet, function($cell, $val, $matches) use(&$loops){
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
        if(count($loops) == 0){return;}

        // looping item
        foreach($loops as $table => $loop_item){
            if(!array_has($loop_item, 'start') 
                || !array_has($loop_item, 'end')
                || !array_has($loop_item, 'items')
            ){continue;}
            // get children value
            $children = getChildrenValues($this->model, $table);

            // get excel row using $loop_item['start']
            $row = intval(array_get($loop_item, 'start'));
            $end = intval(array_get($loop_item, 'end'));

            // looping $children
            foreach($children as $child){
                // loop items
                foreach($loop_item['items'] as $column_name => $sheet_column_no){
                    // output sheet
                    $text = $this->replaceText($child->getValue($column_name, true), []);
                    $sheet->setCellValue($sheet_column_no . $row, $text);
                }

                $row++;
                if($row > $end){break;}
            }
        }
    }

    /**
     * Write default value
     */
    protected function lfValue($sheet)
    {
        // first time, define loop value
        $this->callbackSheetCell($sheet, function($cell, $val, $matches) use($sheet){
            $text = $this->getText($val);
            $sheet->setCellValue($cell->getColumn() . $cell->getRow(), $text);
        });
    }

    protected function callbackSheetCell($sheet, $callback){
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $cellValue = $cell->getValue() ?? null;

                if (is_nullorempty($cellValue)) {
                    continue;
                }
                // if match value
                preg_match_all('/\${(.*?)\}/', $cellValue, $matches);
                if(count($matches) == 0){
                    continue;
                }

                // split ":"
                if(is_null($matches[1]) || count($matches[1]) == 0){continue;}

                // execute callback
                $callback($cell, $cellValue, $matches);
            }
        }
    }

    /**
     * get output text from document item
     */
    protected function getText($text, $documentItem = []){
        // check string
        preg_match_all('/\${(.*?)\}/', $text, $matches);
        if (isset($matches)) {
            // loop for matches. because we want to get inner {}, loop $matches[1].
            for ($i = 0; $i < count($matches[1]); $i++) {
                try{
                    $match = strtolower($matches[1][$i]);
                
                    // get column
                    $length_array = explode(":", $match);
                    
                    ///// value
                    if (strpos($match, "value") !== false) {
                        // get value from model
                        if (count($length_array) <= 1) {
                            $str = '';
                        }
                        else{
                            // get comma string from index 1.
                            $length_array = array_slice($length_array, 1);
                            $str = getValue($this->model, implode(',', $length_array), true, array_get($documentItem, 'format'));
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                    ///// sum
                    elseif (strpos($match, "sum") !== false) {
                        // get sum value from children model
                        if (count($length_array) <= 2) {
                            $str = '';
                        }
                        //else, getting value using cihldren
                        else{
                            // get children values
                            $children = getChildrenValues($this->model, $length_array[1]);
                            // looping
                            $sum = 0;
                            foreach($children as $child){
                                // get value
                                $sum += intval(str_replace(',', '', $child->getValue($length_array[2])));
                            }
                            $str = strval($sum);
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                    // base_info
                    elseif(strpos($match, "base_info") !== false){
                        $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
                        // get value from model
                        if (count($length_array) <= 1) {
                            $str = '';
                        }else{
                            $str = getValue($base_info, $length_array[1], true, array_get($documentItem, 'format'));
                        }
                        $text = str_replace($matches[0][$i], $str, $text);
                    }
                } catch(Exception $e) {
                }
            }
        }

        return $this->replaceText($text, $documentItem);
    }

    /**
     * replace text. ex.comma, &yen, etc...
     */
    protected function replaceText($text, $documentItem){
        // add comma if number_format
        if(array_key_exists('number_format', $documentItem) && !str_contains($text, ',') && is_numeric($text)){
            $text = number_format($text);
        }

        // replace <br/> or \r\n, \n, \r to new line
        $text = preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $text);
        // &yen; to 
        $text = str_replace("&yen;", "¥", $text);

        return $text;
    }
    
    protected function getDefaultOptions(){
        return [
            'width' => 0,
            'height' => 0,
            'font_size' => $this->FontSizePt,
            'format' => '',
            'style' => '',
            'fillColor' => '',
            'color' => '',
            'align' => 'L',
            'valign' => 'M',
            'position' => 'L',
            'border' => '',
            'fixWidth' => false,
            'target_table' => '',
            'table_count' => 5,
            'target_columns' => [],
            'footers' => [],
        ];
    }
}

?>
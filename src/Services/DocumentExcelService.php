<?php
namespace Exceedone\Exment\Services;

use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DocumentExcelService
{
    /**
     *
     */
    protected $baseInfo;
    protected $tempfilename;
    protected $outputfilename;
    protected $filename;

    protected $model;
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

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($this->getFullPath());

        // remove tmpfile
        $file = \File::delete($this->getFullPathTmp());

        return true;
    }

    /**
     * Write Table
     */
    protected function lfTable($sheet)
    {
        // first time, define loop value
        $loops = [];
        $this->callbackSheetCell($sheet, function ($cell, $val, $matches) use (&$loops, $sheet) {
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
                    $loops[$splits[1]]['columns'][$cell_column]['formats'][] = [
                        'format_text' => $matches[0][$index],
                        'column_name' => $column_name,
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
                    foreach($column_item['formats'] as $format){
                        // replace using format
                        $text = str_replace($format['format_text'], $child->getValue($format['column_name'], true, [
                            'disable_currency_symbol' => true,
                            'disable_number_format' => true,
                        ]), $text);
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
            $sheet->setCellValue($cell->getColumn() . $cell->getRow(), $text);
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
    protected function getText($text, $options = [])
    {
        $options['disable_currency_symbol'] = true;
        $options['disable_number_format'] = true;
        $options['afterCallback'] = function($text, $custom_value, $options){
            return $this->replaceText($text, $options);
        };
        return replaceTextFromFormat($text, $this->model, $options);
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
    
    /**
     * get Directory full path from root
     * @return string File path
     */
    public function getFullPathTmp()
    {
        $filepath = path_join($this->getDirPath(), $this->getFileName().'tmp');
        return getFullpath($filepath, config('admin.upload.disk'));
    }
}

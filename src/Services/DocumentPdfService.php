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

/**
 * FPDI Wrapper Class.
 */
abstract class AbstractFPDIService extends Fpdi\TcpdfFpdi
{
}


/**
 * Class CreatePdfService.
 * Do export pdf function.
 */
class DocumentPdfService extends AbstractFPDIService
{
    /** FONT Gothic */
    const FONT_GOTHIC = 'kozgopromedium';

    // --------------------------------------
    // Font backup
    /** @var string Font Style */
    private $bakFontStyle;
    /** @var string Font size */
    private $bakFontSize;
    // lfText's offset
    private $baseOffsetX = 0;
    private $baseOffsetY = -4;

    /** Download file name @var string */
    private $downloadFileName = null;
    /* font size */
    private $fontSize = 10;

    /**
     *
     */
    private $baseInfo;
    private $documentInfo;
    private $documentItems;

    private $model;
    /**
     * construct
     * @param Request $request
     * @param $document
     */
    public function __construct($model, $documentInfo, $documentItems)
    {
        $this->model = $model;
        $this->documentInfo = $documentInfo;
        $this->documentItems = $documentItems;

        parent::__construct();

        // // Set margin PDF
        $this->SetMargins(20, 20);
        $this->baseOffsetX = 20;
        $this->baseOffsetY = 20;
        // // disable print header and footer
        $this->setPrintHeader(false);
        $this->setPrintFooter(true);

        $this->AddPage();
    }

    /**
     * Create PDF
     * @return boolean
     */
    public function makePdf()
    {
        // Add Document Item using $documentItems array
        foreach($this->documentItems as $documentItem)
        {
            //tables
            if(array_get($documentItem, 'document_item_type') == 'table'){
                // get children
                $children = getChildrenValues($this->model, array_get($documentItem, 'target_table'));
                $this->lfTable($children, $documentItem);
                continue;
            }

            // get image
            // TODO: COPY PASTE!!! use function.
            $image = array_get($documentItem, 'image');
            if (isset($image)) {
                // check string
                preg_match_all('/\${(.*?)\}/', $image, $matches);
                if (isset($matches)) {
                    // loop for matches. because we want to get inner {}, loop $matches[1].
                    for ($i = 0; $i < count($matches[1]); $i++) {
                        try {
                            $match = strtolower($matches[1][$i]);
                    
                            // get column
                            $length_array = explode(":", $match);
                        
                            ///// value
                            if (strpos($match, "value") !== false) {
                                // get value from model
                                if (count($length_array) <= 1) {
                                    $image = null;
                                } else {
                                    // todo:how to get only path
                                    $image = array_get(array_get($this->model, 'value'), $length_array[1]);
                                }
                            } elseif (strpos($match, "base_info") !== false) {
                                $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
                                // get value from model
                                if (count($length_array) <= 1) {
                                    $image = null;
                                } else {
                                    $image = array_get(array_get($base_info, 'value'), $length_array[1]);
                                }
                            }
                        } catch (Exception $e) {
                        }
                    }
                }

                if(!isset($image)){continue;}
                // write image
                $this->lfImage($image, $documentItem);
                continue;
            }

            // get text
            $text = $this->getText(array_get($documentItem, 'text'), $documentItem);

            // write text
            $this->lfText($text, $documentItem);
        }

        return true;
    }

    /**
     * @return string|mixed
     */
    public function outputPdf()
    {
        return $this->Output($this->getPdfFileName(), 'S');
    }

    /**
     * get pdf file name
     *  When there is one PDF, attach the order number to the file name.
     * @return string File name
     */
    public function getPdfFileName()
    {
        // get document file name
        $filename = $this->getText(array_get($this->documentInfo, 'filename'));
        if(!isset($filename)){
            $filename = make_uuid();
        }
        // set from document_type
        $this->downloadFileName = $filename.'.pdf';
        return $this->downloadFileName;
    }

    /**
     * get PDF file path
     */
    public function getPdfPath()
    {
        return 'document/'.$this->getPdfFileName();
    }

    /**
     * add new pdf page
     */
    protected function addPdfPage()
    {
        // Add Page
        $this->AddPage();
        //Get page number of template file to use for template
        $tplIdx = $this->importPage(1);
        //Specify the page number of the template file to use for the template
        $this->useTemplate($tplIdx, null, null, null, null, true);
    }
    
    /**
     * Write Text
     * @param int $x X
     * @param int $y Y
     * @param string $text Writing text
     * @param int $size Font Size
     * @param string $style Font Style
     */
    protected function lfText($text, $options = [])
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });

        // merge options to default
        $options = array_merge($this->getDefaultOptions(), $options);

        // get x and y
        $x = array_get($options, 'x');
        $y = array_get($options, 'y');     

        // Escape Font
        $bakFontStyle = $this->FontStyle;
        $bakFontSize = $this->FontSizePt;
        $this->SetFont(self::FONT_GOTHIC, $options['style'], $options['font_size']);

        // if fix width, get content width
        if(boolval($options['fixWidth'])){
            $width = $this->getContentWidth();
            $x = 0;
        }

        $this->setMultiCell(
            $text,
            $this->getOutputX($x, $options),
            $this->getOutputY($y, $options),
            $options
        );
        // Restore
        $this->SetFont('', $bakFontStyle, $bakFontSize);
    }
    
    /**
     * Write Image
     * @param string $text Writing text
     * @param int $size Font Size
     * @param string $style Font Style
     */
    protected function lfImage($image, $options)
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });
        // merge options to default
        $options = array_merge($this->getDefaultOptions(), $options);
        
        // get x and y
        $x = array_get($options, 'x');
        $y = array_get($options, 'y');     

        $path = getFullpath($image, 'admin');
        $this->Image($path, 
            $this->getOutputX($x, $options),
            $this->getOutputY($y, $options),
            $options['width'], 
            $options['height'],
            '',  //$type
            '',  //$link
            '', //$align=
            true //resize
        );
    }
        
    /**
     * Write Table
     */
    protected function lfTable($children, $options = [])
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });
        // merge options to default
        $options = array_merge(
            $this->getDefaultOptions(),
            [
                'table_count' => 5,
                'fill' => true,
                'fixed_table_count' => false,
            ],
            $options
        );

        // get x and y
        $x = array_get($options, 'x');
        $y = array_get($options, 'y');
        $target_columns = array_get($options, 'target_columns');

        // set base position
        $this->setBasePosition($x, $y);

        // Escape Font
        $bakFontStyle = $this->FontStyle;
        $bakFontSize = $this->FontSizePt;
        $this->SetFont(self::FONT_GOTHIC, $options['style'], $options['font_size']);

        // set default options to $target_columns
        $this->setItemProps($target_columns);

        // get content width
        $contentWidth = $this->getContentWidth();
        // set header info.
        foreach($target_columns as &$target_column){
            // set header name using table
            if (!array_key_value_exists('label', $target_column)) {
                $target_column['label'] =
                CustomTable::findByName($options['target_table'])
                ->custom_columns()
                ->where('column_name', array_get($target_column, 'column_name'))
                ->first()->column_view_name ?? '';
            }

            // set default target_column
            $target_column['border'] = 1;

            // set header options
            $table_header = array_merge(
                $this->getDefaultOptions(),
                ['ln' => 0, 'real_width' => array_get($target_column, 'real_width')],
                array_get($options, 'table_header', [])
            );

            // set table header
            $this->setMultiCell($target_column['label'], '', '', 
                array_set($table_header,'real_width', $target_column['real_width'])
            );
        }

        // set table body --------------------------------------------------
        $this->SetFillColor(245, 245, 245);
        $this->Ln();
        $fill = false;
        for ($i=0; $i < $options['table_count']; $i++) { 
            // if param fixed_table_count is true, and end of $children, break
            if($i >= count($children) && boolval(array_get($options, 'fixed_table_count'))){
                break;
            }
            $child = $children[$i] ?? null;
            $x0 = $this->GetX();
            $this->SetX($x0);
            $y0 = $this->GetY();

            foreach($target_columns as $index => &$target_column){
                ///// get text
                // has column_name
                if(array_key_value_exists('column_name', $target_column)){
                    $text = getValue($child, array_get($target_column, 'column_name'), true, array_get($target_column, 'format'));
                    $text = $this->replaceText($text, $target_column);
                }
                elseif(array_key_value_exists('loop_index', $target_column)){
                    $text = $index;
                }
                elseif(array_key_value_exists('loop_no', $target_column)){
                    $text = ($index + 1);
                }
                else{
                    continue;
                }
                    
                $this->setMultiCell(
                    $text,
                    '',
                    '',
                    array_set($target_column, 'height', 7)
                );
                $x0 += $target_column['real_width'];
                $this->SetXY($x0, $y0);
            }

            // set fill(if use fill)
            $fill = $options['fill'] ? !$fill : false;
            
            $this->Ln();
        }

        // set table footer --------------------------------------------------
        $footers = array_get($options, 'table_footers', []);
        foreach($footers as &$footer){
            // set default options to $target_columns
            $footer_array = [array_get($footer, 'header', []), array_get($footer, 'body', [])];
            $this->setItemProps($footer_array);
            $footer_header = $footer_array[0];
            $footer_body = $footer_array[1];

            // get x using real_width
            $x0 = $this->getOutputX(0, array_set($footer, 'width', $footer_header['real_width'] + $footer_body['real_width']));
            $this->SetX($x0);
            $y0 = $this->GetY();

            /////TODO: COPY AND PASTE!!
            // set label
            $this->setMultiCell(
                $this->getText(array_get($footer_header, 'text'), $footer_header),
                '',
                '',
                $footer_header
            );

            $x0 += intval($footer_header['real_width']);
            $this->SetXY($x0, $y0);

            // set item
            $this->setMultiCell(
                $this->getText(array_get($footer_body, 'text'), $footer_body),
                '',
                '',
                $footer_body
            );
        }

        // Restore
        $this->SetFont('', $bakFontStyle, $bakFontSize);
    }

    protected function setMultiCell($text, $x, $y, $options){
        // set fillColor
        if(array_key_value_exists('fillColor', $options)){
            $rgb = hex2rgb(array_get($options, 'fillColor'));
            $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
            $fill = true;
        }else{
            $fill = false;
        }
        // set Color
        if(array_key_value_exists('color', $options)){
            $rgb = hex2rgb(array_get($options, 'color'));
            $this->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        }
        // default
        else{
            $this->SetTextColor(0, 0, 0);
        }

        $this->MultiCell(
            array_get($options, 'real_width', array_get($options, 'width', 0)),
            array_get($options, 'real_height', array_get($options, 'height', 0)),
            trim($text),
            array_get($options, 'border', 0), 
            array_get($options, 'align', 'L'), 
            $fill, //fill 
            array_get($options, 'ln', 1),  // ln
            $x,
            $y, 
            $reseth = true, 
            $strech = 0, 
            $ishtml = false, 
            $autopadding = true, 
            $maxh=0, 
            array_get($options, 'valign', ''), 
            true
        );
    }

    /**
     * set real width, default prop, ...
     */
    protected function setItemProps(&$items){
        $contentWidth = $this->getContentWidth();
        if(!is_array($items)){
            $items = [$items];
        }

        // looping items
        foreach($items as &$item){
            // set default options
            $item = array_merge($this->getDefaultOptions(), $item);

            // calc real width
            // calc width
            if (is_numeric(array_get($item, 'width'))) {
                $item['real_width'] = intval(array_get($item, 'width'));
            }
            // when *, set 0
            else {
                $item['real_width'] = 0;
            }
        }

        // re-loop
        foreach($items as &$item){
            // if width is *, calc real_width
            if(array_get($item, 'width') == '*'){
                $item['real_width'] = $contentWidth - collect($items)->sum('real_width');
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
    
    /**
     * Get Content Width
     */
    protected function getContentWidth(){
        $margins = $this->getMargins();
        return $this->GetPageWidth() - $margins['left'] - $margins['right'];
    }

    /**
     * Get Content Height
     */
    protected function getContentHeight(){
        $margins = $this->getMargins();
        return $this->getPageHeight() - $margins['top'] - $margins['bottom'];
    }

    /**
     * get output x for text or image
     */
    protected function getOutputX($x, $options){
        // if option->position Contains 'R', calc for document right
        if(str_contains(array_get($options, 'position'), 'R')){
            return $this->GetPageWidth() - ($x + $this->baseOffsetX + array_get($options, 'width'));
        }
        // if center
        elseif(str_contains(array_get($options, 'position'), 'C')){
            return ($this->GetPageWidth() - array_get($options, 'width')) / 2;
        }

        // default
        return $x + $this->baseOffsetX;
    }
    
    /**
     * get output y for text or image
     */
    protected function getOutputY($y, $options){
        // if option->position Contains 'B', calc for document right
        if(str_contains(array_get($options, 'position'), 'B')){
            return $this->getContentHeight() - ($y + $this->baseOffsetY + array_get($options, 'height'));
        }

        // default
        return $y + $this->baseOffsetY;
    }
    
    /**
     * Set base position.
     *
     * @param int $x
     * @param int $y
     */
    protected function setBasePosition($x = null, $y = null)
    {
        // Get current margin position.
        $result = $this->getMargins();
        // Set base position
        $actualX = is_null($x) ? $result['left'] : $result['left'] + $x;
        $this->SetX($actualX);
        $actualY = is_null($y) ? $result['top'] : $result['top'] + $y;
        $this->SetY($actualY);
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
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
 * FPDIのラッパークラス.
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
    // ====================================
    // 定数宣言
    // ====================================

    /** 通貨単位 */
    const MONETARY_UNIT = '円';

    /** FONT ゴシック */
    const FONT_GOTHIC = 'kozgopromedium';
    /** FONT 明朝 */
    const FONT_SJIS = 'kozminproregular';

    // ====================================
    // 変数宣言
    // ====================================

    /*** 購入詳細情報 幅サイズ配列
     * @var array
     */
    private $widthCell = array();

    // --------------------------------------
    // Font情報のバックアップデータ
    /** @var string フォント名 */
    private $bakFontFamily;
    /** @var string フォントスタイル */
    private $bakFontStyle;
    /** @var string フォントサイズ */
    private $bakFontSize;
    // lfTextのoffset
    private $baseOffsetX = 0;
    private $baseOffsetY = -4;

    /** ダウンロードファイル名 @var string */
    private $downloadFileName = null;
    /*font size */
    private $fontSize = 10;

    /**
     *
     */
    private $baseInfo;

    /**
     * 作成するDocument情報
     */
    private $document;

    /**
     * コンストラクタ.
     * @param Request $request
     * @param $document
     */
    public function __construct()
    {
        //$this->document = $document;

        // set baseInfo
        //$this->baseInfo = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();

        parent::__construct();

        // // Fontの設定しておかないと文字化けを起こす
        // $this->SetFont(self::FONT_SJIS);
        // // Set margin PDF
        $this->SetMargins(20, 20);
        $this->baseOffsetX = 20;
        $this->baseOffsetY = 20;
        // // ヘッダーの出力を無効化
        $this->setPrintHeader(false);
        // // フッターの出力を無効化
        $this->setPrintFooter(true);

        $this->AddPage();
        // $this->setFooterMargin();
        // $this->setFooterFont(array(self::FONT_SJIS, '', 8));
    }

    /**
     * Create PDF
     * @return boolean
     */
    public function makeContractPdf($model, $documentInfo, $documentItems = [])
    {
        // Add Document Item using $documentItems array
        foreach($documentItems as $documentItem)
        {
            $x = array_get($documentItem, 'x');
            $y = array_get($documentItem, 'y');
            $width = array_get($documentItem, 'width', 0);
            $height = array_get($documentItem, 'height', 0);

            $font_size = array_get($documentItem, 'font_size', $this->fontSize);
            $font_style = array_get($documentItem, 'font_style');
            $align = array_get($documentItem, 'align');
            $border = array_get($documentItem, 'border');
            $fixWidth = array_get($documentItem, 'fixWidth', false);
            
            //tables
            if(array_get($documentItem, 'document_item_type') == 'table'){
                // get children
                $target_table_name = array_get($documentItem, 'target_table');
                $children = getChildrenValues($model, array_get($documentItem, 'target_table'));
                $table_count = array_get($documentItem, 'table_count', 5);
                $target_columns = array_get($documentItem, 'target_columns', []);

                $this->lfTable($x, $y, $target_table_name, $children, $target_columns, [
                    'font_size' => $font_size,
                    'font_style' => $font_style,
                    'table_count' => $table_count,
                ]);
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
                                    $image = array_get($model->value, $length_array[1]);
                                }
                            } elseif (strpos($match, "base_info") !== false) {
                                $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
                                // get value from model
                                if (count($length_array) <= 1) {
                                    $image = null;
                                } else {
                                    $image = array_get($base_info->value, $length_array[1]);
                                }
                            }
                        } catch (Exception $e) {
                        }
                    }
                }

                if(!isset($image)){continue;}
                // write image
                $this->lfImage($image, $x, $y,  [
                    'width' => $width,
                    'height' => $height,
                    'align' => $align,
                ]);
                continue;
            }

            // get text
            $text = array_get($documentItem, 'text');
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
                            }else if(count($length_array) == 2) {
                                $str = getValue($model, $length_array[1], true);
                            }else{
                                $str = getValue($model, $length_array[1], $length_array[2]);
                            }
                            $text = str_replace($matches[0][$i], $str, $text);
                        }
                        else if(strpos($match, "base_info") !== false){
                            $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
                            // get value from model
                            if (count($length_array) <= 1) {
                                $str = '';
                            }else{
                                $str = getValue($base_info, $length_array[1]);
                            }
                            $text = str_replace($matches[0][$i], $str, $text);
                        }
                    } catch(Exception $e) {
                    }
                }
            }

            // write text
            $this->lfText($x, $y, $text, 
            [
                'width' => $width,
                'height' => $height,
                'font_size' => $font_size,
                'font_style' => $font_style,
                'align' => $align,
                'border' => $border,
                'fixWidth' => $fixWidth,
            ]);
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
     * PDFが1枚の時は注文番号をファイル名につける.
     * @return string ファイル名
     */
    public function getPdfFileName()
    {
        if (!is_null($this->downloadFileName)) {
            return $this->downloadFileName;
        }
        // get filename from document_type
        //$document_array = array_get(Define::DOCUMENT_TYPE, $this->document->document_type);
        //TODO:hsato
        //$this->downloadFileName = $document_array['label']. '_' . $this->document->document_code . '.pdf';
        $this->downloadFileName = make_uuid().'.pdf';
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
        // ページを追加
        $this->AddPage();
        // テンプレートに使うテンプレートファイルのページ番号を取得
        $tplIdx = $this->importPage(1);
        // テンプレートに使うテンプレートファイルのページ番号を指定
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
    protected function lfText($x, $y, $text, $options = [])
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });
        // merge options to default
        $options = array_merge(
            [
                'width' => 0,
                'height' => 0,
                'font_size' => $this->FontSizePt,
                'style' => '',
                'align' => 'J',
                'border' => '',
                'fixWidth' => false,
            ],
            $options
        );

        // Escape Font
        $bakFontStyle = $this->FontStyle;
        $bakFontSize = $this->FontSizePt;
        $this->SetFont(self::FONT_GOTHIC, $options['style'], $options['font_size']);

        // if fix width, get content width
        if(boolval($options['fixWidth'])){
            $width = $this->getContentWidth();
            $x = 0;
        }

        //$this->Text($x + $this->baseOffsetX, $y + $this->baseOffsetY, $text);
        $this->MultiCell(
            $options['width'], 
            $options['height'], 
            trim($text), 
            $options['border'], // border
            $options['align'], // align
            null, // fill
            1, //ln
            $x + $this->baseOffsetX, 
            $y + $this->baseOffsetY
        );
        // Restore
        $this->SetFont('', $bakFontStyle, $bakFontSize);
    }
    
    /**
     * Write Image
     * @param int $x X
     * @param int $y Y
     * @param string $text Writing text
     * @param int $size Font Size
     * @param string $style Font Style
     */
    protected function lfImage($image, $x, $y, $options)
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });
        // merge options to default
        $options = array_merge(
            [
                'width' => 0,
                'height' => 0,
                'align' => 'L',
            ],
            $options
        );

        // if $options['align'] == 'R', re-set $x
        if($options['align'] == 'R'){
            $x = $this->getContentWidth() -$x - $options['width'];
        }

        $path = getFullpath($image, 'admin');
        $this->Image($path, 
            $x + $this->baseOffsetX, 
            $y + $this->baseOffsetY,
            $options['width'], 
            $options['height']
        );
    }
        
    /**
     * Write Table
     */
    protected function lfTable($x, $y, $target_table_name, $children, $target_columns, $options = [])
    {
        // remove null value
        array_filter($options, function($value) { return $value !== ''; });
        // merge options to default
        $options = array_merge(
            [
                'font_size' => $this->FontSizePt,
                'style' => '',
                'table_count' => 5,
                'fill' => true,
            ],
            $options
        );

        // set base position
        $this->setBasePosition($x, $y);

        // Escape Font
        $bakFontStyle = $this->FontStyle;
        $bakFontSize = $this->FontSizePt;
        $this->SetFont(self::FONT_GOTHIC, $options['style'], $options['font_size']);

        // get content width
        $contentWidth = $this->getContentWidth();
        // set header info.
        // get real_width if not *
        foreach($target_columns as &$target_column){
            if(is_numeric(array_get($target_column, 'width'))){
                $target_column['real_width'] = intval(array_get($target_column, 'width'));
            }
            // when *, set 0
            else{
                $target_column['real_width'] = 0;
            }

            // set header name using table
            $target_column['label'] = 
                CustomTable::findByName($target_table_name)
                ->custom_columns()
                ->where('column_name', array_get($target_column, 'column_name'))
                ->first()->column_view_name ?? '';

            // set default target_column
            $target_column = array_merge([
                'align' => 'J',
            ], $target_column);
        }
        // set real_width
        $this->SetFillColor(216, 216, 216);
        foreach($target_columns as &$target_column){
            if(array_get($target_column, 'width') == '*'){
                $target_column['real_width'] = $contentWidth - collect($target_columns)->sum('real_width');
            }

            // set table header
            $this->Cell($target_column['real_width'], 7, $target_column['label'], 1, 0, 'C', true);
        }

        // set table body --------------------------------------------------
        $this->SetFillColor(245, 245, 245);
        $this->Ln();
        $fill = false;
        for ($i=0; $i < $options['table_count']; $i++) { 
            $child = $children[$i] ?? null;
            $x0 = $this->GetX();
            $this->SetX($x0);
            $y0 = $this->GetY();

            foreach($target_columns as &$target_column){
                $option_detail = [];
                $this->MultiCell(
                    $target_column['real_width'], 
                    7, 
                    getValue($child, array_get($target_column, 'column_name'), true) ?? '', 
                    1, 
                    $target_column['align'], 
                    $fill, 
                    1, 
                    '', 
                    '', 
                    $reseth = true, 
                    $strech = 0, 
                    $ishtml = false, 
                    $autopadding = true, 
                    $maxh=0, 
                    $valign = 'M', 
                    true
                );
                $x0 += $target_column['real_width'];
                $this->SetXY($x0, $y0);
            }

            // set fill(if use fill)
            $fill = $options['fill'] ? !$fill : false;
            
            $this->Ln();
        }

        // Restore
        $this->SetFont('', $bakFontStyle, $bakFontSize);
    }
    
    /**
     * Get Content Width
     */
    protected function getContentWidth(){
        $margins = $this->getMargins();
        return $this->GetPageWidth() - $margins['left'] - $margins['right'];
    }
    
    /**
     * 基準座標を設定する.
     *
     * @param int $x
     * @param int $y
     */
    protected function setBasePosition($x = null, $y = null)
    {
        // 現在のマージンを取得する
        $result = $this->getMargins();
        // 基準座標を指定する
        $actualX = is_null($x) ? $result['left'] : $result['left'] + $x;
        $this->SetX($actualX);
        $actualY = is_null($y) ? $result['top'] : $result['top'] + $y;
        $this->SetY($actualY);
    }
}

?>
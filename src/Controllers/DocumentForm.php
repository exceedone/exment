<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Services\DocumentPdfService;
trait DocumentForm
{

    public function __construct(){
        parent::__construct('ドキュメント', '');
    }

    /**
     * Create Document
     */
    public function getDocumentForm(Request $request, $id){
        $table_name = $this->custom_table->table_name;
        $model = getModelName($table_name)::find($id);
        
        $documentItems = $this->getDocumentItem($table_name);

        $service = new DocumentPdfService();
        $service->makeContractPdf($model, null, $documentItems);

        // save
        $document_attachment_file = $service->getPdfPath();
        // save pdf
        $path = $this->savePdfInServer($document_attachment_file, $service);
        $filename = $service->getPdfFileName();

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            //'Content-Disposition' => 'inline; filename="'.$filename.'"'
            'Content-Disposition' => 'inline; filename="'.$filename.'"'
        ]);

    }

    /**
     * save pdf and get pdf fullpath
     * @param $response
     * @param $service
     */
    protected function savePdfInServer($path, $service)
    {
        $path = File::put('admin', $path, $service->outputPdf());
        return getFullpath($path, 'admin');
    }
    
    protected function getDocumentItem($table_name){
        // estimate
        if ($table_name == 'estimate') {
            return [
                ['y' => 0, 'fixWidth' => true, 'text' => '見積書', 'align' => 'C', 'font_size' => 20, 'border' => 'TLBR'],
                
                // TO:customer
                ['x' => 0, 'y' => 20, 'width' => 95, 'text' => '${value:deal:company_customer:company_name}', 'font_size' => 15, 'border' => 'B'],
                ['x' => 95, 'y' => 20, 'text' => '様'],
                ['x' => 0, 'y' => 35, 'width' => 30, 'text' => '${sum:'.$table_name.'_detail:sum_tax_price}', 'font_size' => 14, 'align' => 'R', 'border' => 'B', 'number_format' => true, 'valign', 'B'],
                ['x' => 30, 'y' => 35, 'text' => '円(税込)', 'valign', 'B'],

                // company info
                ['x' => 0, 'y' => 35, 'width' => 20, 'image' => '${base_info:company_stamp}', 'align' => 'R'],
                ['x' => 0, 'y' => 20, 'width' => 40, 'image' => '${base_info:company_logo}', 'align' => 'R'],
                ['y' => 50, 'fixWidth' => true, 'text' => '${base_info:company_name}', 'align' => 'R', 'font_size' => 14],
                ['y' => 55, 'fixWidth' => true, 'text' => '〒${base_info:zip01}-${base_info:zip02}', 'align' => 'R', 'font_size' => 10],
                ['y' => 60, 'fixWidth' => true, 'text' => '${base_info:pref}${base_info:addr01} ${base_info:addr02}', 'align' => 'R', 'font_size' => 10],
                ['y' => 65, 'fixWidth' => true, 'text' => 'TEL:${base_info:tel01}-${base_info:tel02}-${base_info:tel03}', 'align' => 'R', 'font_size' => 10],

                // tables
                ['y' => 90, 'fixWidth' => true, 'document_item_type' => 'table', 'font_size' => 8, 'target_table' => $table_name.'_detail', 'table_count' => 10, 'target_columns' => [
                    ['column_name' => 'merchandise', 'width' => '*'],
                    ['column_name' => 'price', 'width' => '20', 'align' => 'R'],
                    ['column_name' => 'num', 'width' => '12', 'align' => 'C'],
                    ['column_name' => 'unit', 'width' => '12', 'align' => 'C'],
                    ['column_name' => 'sum_price', 'width' => '20', 'align' => 'R'],
                ], 'footers' => [
                    ['header' => ['text' => '合計(税抜)', 'align' => 'R', 'width' => '*'], 'body' => ['width' => '32', 'text' => '${sum:'.$table_name.'_detail:sum_price}', 'align' => 'R', 'number_format' => true]],
                    ['header' => ['text' => '消費税', 'align' => 'R', 'width' => '*'], 'body' => ['width' => '32', 'text' => '${sum:'.$table_name.'_detail:tax_price}', 'align' => 'R', 'number_format' => true]],
                    ['header' => ['text' => '合計(税込)', 'align' => 'R', 'width' => '*'], 'body' => ['width' => '32', 'text' => '${sum:'.$table_name.'_detail:sum_tax_price}', 'align' => 'R', 'number_format' => true]],
                ]],

                // comment
                ['y' => 200, 'fixWidth' => true, 'text' => '備考', 'font_size' => 10],
                ['y' => 205, 'fixWidth' => true, 'height' => 50, 'border' => 'TLBR', 'text' => '${value:tekiyou}'],

                ['x' => 0, 'y' => 45, 'text' => '有効期限：'.Carbon::today()->addMonth()->addDay(-1)->format('Y/m/d')],
                ['y' => 85, 'fixWidth' => true, 'text' => '下記の通りお見積り申し上げます。', 'font_size' => 10],
            ];
        }else{
            return [
                ['y' => 0, 'width' => 50, 'text' => '請　求　書', 'align' => 'C', 'position' => 'C', 'font_size' => 17, 'border' => 'B'],
                
                // TO:customer
                ['x' => 0, 'y' => 20, 'width' => 60, 'text' => '${value:reseller:reseller_name} 御中', 'font_size' => 11, 'border' => 'B', 'align' => 'J'],

                // stamp
                ['x' => 0, 'y' => 15, 'width' => 25, 'image' => '${base_info:company_stamp}', 'align' => 'L', 'position' => 'R'],
                // company info
                ['x' => 110, 'y' => 20, 'height' => 10, 'image' => '${base_info:company_logo}', 'align' => 'L'],
                ['x' => 110, 'y' => 30, 'width' => 60, 'text' => '${base_info:company_name}', 'align' => 'L', 'font_size' => 10, ],
                ['x' => 110, 'y' => 37, 'width' => 60, 'text' => '〒${base_info:zip01}-${base_info:zip02}', 'align' => 'L', 'font_size' => 8],
                ['x' => 110, 'y' => 42, 'width' => 60, 'text' => '${base_info:pref}${base_info:addr01} ${base_info:addr02}', 'align' => 'L', 'font_size' => 8],
                ['x' => 110, 'y' => 47, 'width' => 60, 'text' => 'TEL:${base_info:tel01}-${base_info:tel02}-${base_info:tel03}', 'align' => 'L', 'font_size' => 8],

                // 
                ['x' => 0, 'y' => 40, 'text' => '平素よりお引き立てを賜り誠にありがとうございます。\n下記のとおりご請求申し上げます。', 'font_size' => 9],
                ['x' => 5, 'y' => 50, 'text' => '件名　　　：${value:claim_name}', 'font_size' => 9],
                ['x' => 5, 'y' => 55, 'text' => 'お支払方法：納品月末締め翌々10日現金支払 (${value:claim_date})', 'font_size' => 9],

                /// sum
                ['x' => 0, 'y' => 70, 'width' => 30, 'text' => '合計金額', 'font_size' => 14, 'border' => 1, 'align' => 'C', 'height' => 8],
                ['x' => 30, 'y' => 70, 'width' => 60, 'text' => '${sum:claim_detail:total_price}', 'font_size' => 14, 'align' => 'C', 'border' => 'TB', 'number_format' => true, 'valign' => 'M', 'height' => 8],
                ['x' => 90, 'y' => 70, 'width' => 10, 'text' => '円', 'font_size' => 14, 'align' => 'J', 'border' => 'BTR', 'height' => 8],

                // tables
                ['y' => 85, 'fixWidth' => true, 'document_item_type' => 'table', 'font_size' => 8, 'target_table' => 'claim_detail', 'table_count' => 10, 'fixed_table_count' => true, 'target_columns' => [
                    ['label' => '品名', 'column_name' => 'product', 'width' => '*'],
                    ['column_name' => 'num', 'width' => '15', 'align' => 'C'],
                    ['column_name' => 'unit', 'width' => '15', 'align' => 'C'],
                    ['label' => '単価', 'column_name' => 'price', 'width' => '25', 'align' => 'R'],
                    ['label' => '金額', 'column_name' => 'sum_price', 'width' => '25', 'align' => 'R'],
                ],
                'table_header' => [
                    'fillColor' => '#000000',
                    'color' => '#FFFFFF',
                    'align' => 'C', 
                    'height' => 7,
                ],
                'table_footers' => [
                    ['header' => ['text' => '小計', 'align' => 'R', 'width' => '25', 'height' => 7, 'fillColor' => '#000000', 'color' => '#FFFFFF', 'border' => 1,], 'body' => ['width' => '25', 'height' => 7, 'text' => '${sum:claim_detail:sum_price}', 'align' => 'R', 'number_format' => true, 'border' => 1], 'position' => 'R'],
                    ['header' => ['text' => '消費税', 'align' => 'R', 'width' => '25', 'height' => 7, 'fillColor' => '#000000', 'color' => '#FFFFFF', 'border' => 1,], 'body' => ['width' => '25', 'height' => 7, 'text' => '${sum:claim_detail:sum_tax_price}', 'align' => 'R', 'number_format' => true, 'border' => 1], 'position' => 'R'],
                    ['header' => ['text' => '御請求書合計', 'align' => 'R', 'width' => '25','height' => 7, 'fillColor' => '#000000', 'color' => '#FFFFFF', 'border' => 1,], 'body' => ['width' => '25', 'height' => 7, 'text' => '${sum:claim_detail:total_price}', 'align' => 'R', 'number_format' => true, 'border' => 1], 'position' => 'R'],
                ]],

                // comment
                ['y' => 200, 'fixWidth' => true, 'text' => '振込口座', 'font_size' => 10, 'border' => 'TLBR', 'fillColor' => '#000000', 'color' => '#FFFFFF'],
                ['y' => 205, 'fixWidth' => true, 'height' => 30, 'font_size' => 8, 'valign' => 'T', 'border' => 'TLBR', 'text' => 'お手数ではございますが、お支払いは下記銀行口座へお振り込みくださいますよう、お願い申し上げます。\n※お振り込み手数料は、貴社にてご負担いただきますようお願い申しあげます。'],
            ];
        }
    }
}

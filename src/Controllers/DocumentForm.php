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
        // TODO:document items
        $isMitsumori = $table_name == 'estimate';
        $documentItems = [
            ['y' => 0, 'fixWidth' => true, 'text' => $isMitsumori ? '見積書' : '請求書', 'align' => 'C', 'font_size' => 20, 'border' => 'TLBR'],
            
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
        ];
        if($isMitsumori){
            $documentItems[] = ['x' => 0, 'y' => 45, 'text' => '有効期限：'.Carbon::today()->addMonth()->addDay(-1)->format('Y/m/d')];
            $documentItems[] = ['y' => 85, 'fixWidth' => true, 'text' => '下記の通りお見積り申し上げます。', 'font_size' => 10];
        }

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
        

        //$value['filepath'] = $document_attachment_file;

        // return $this->AdminContent(function (Content $content) use($request, $id)
        // {
        //     $document = $this->createDocumentModel($request, $id, 'estimate');
        //     $content->header('見積書作成');
        //     $content->description($this->description);

        //     $content->body(view("exment::document.document", [
        //         'document' => $document, 
        //         'document_labels' => $this->getLabel('estimate'),
        //         'css' => asset('/vendor/exment/css/document.css'),
        //         ]));
        // });
    }

    public function postDocumentForm(Request $request, string $id){
        
        $document_classname = getModelName(Define::SYSTEM_TABLE_NAME_DOCUMENT);
        $document = new $document_classname;
        $document->parent_id = $id;
        $document->parent_type = "contract";
        $value = [];
        $value['client_id'] = $request->input('client_id');
        $value['document_code'] = $request->input('document_code');
        $value['document_type'] = $request->input('document_type');
        $value['document_edaban'] = $request->input('document_edaban');
        $value['document_date'] = \Carbon\Carbon::parse($request->input('document_date'));
        $value['document_limit_date'] = \Carbon\Carbon::parse($request->input('document_limit_date'));
        $value['zeinuki_price'] = parseIntN($request->input('zeinuki_price'));
        $value['tax_rate'] = $request->input('tax_rate');
        $value['tax_price'] = parseIntN($request->input('tax_price'));
        $value['zeikomi_price'] = parseIntN($request->input('zeikomi_price'));
        
        // at json data
        $json_array = [];
        $json_array['title'] = $request->input('title');
        $json_array['to_company_name'] = $request->input('to_company_name');
        $json_array['to_company_honorific'] = $request->input('to_company_honorific');
        $json_array['to_contact_name'] = $request->input('to_contact_name');
        $json_array['to_tel'] = $request->input('to_tel');
        $json_array['to_mail'] = $request->input('to_mail');
        $json_array['to_contact_honorific'] = $request->input('to_contact_honorific');

        $json_array['from_company_name'] = $request->input('from_company_name');
        $json_array['from_zip'] = $request->input('from_zip');
        $json_array['from_addr01'] = $request->input('from_addr01');
        $json_array['from_addr02'] = $request->input('from_addr02');
        $json_array['from_tel'] = $request->input('from_tel');
        $json_array['from_fax'] = $request->input('from_fax');
        $json_array['from_mail'] = $request->input('from_mail');
        $json_array['header_comment'] = $request->input('header_comment');
        $json_array['comment'] = $request->input('comment');

        //$document->document_data = $json_array;

        // $document->create_user = Admin::user()->name;
        // $document->update_user = Admin::user()->name;

        // 詳細も作成
        // foreach ($request->input('document_details') as $req_document_detail)
        // {
        //     $document_detail = new Model\DocumentDetail;
        //     // 削除時
        //     if(array_value('_file_del_', $req_document_detail) == true){
        //         continue;
        //     }
        //     $document_detail->relation_id = array_value('relation_id', $req_document_detail);
        //     $document_detail->name =  array_value('name', $req_document_detail);
        //     $document_detail->fixed_price =  parseIntN(array_value('fixed_price', $req_document_detail));
        //     $document_detail->zeinuki_price =  parseIntN(array_value('zeinuki_price', $req_document_detail));
        //     $document_detail->wholecontract_rate =  array_value('wholecontract_rate', $req_document_detail);
        //     $document_detail->tax_rate = array_value('tax_rate', $req_document_detail);
        //     $document_detail->tax_price =  parseIntN(array_value('tax_price', $req_document_detail));
        //     $document_detail->zeikomi_price =  parseIntN(array_value('zeikomi_price', $req_document_detail));
        //     $document_detail->num =  parseIntN(array_value('num', $req_document_detail));
        //     $document_detail->total_zeinuki_price =  parseIntN(array_value('total_zeinuki_price', $req_document_detail));
        //     $document_detail->total_tax_price =  parseIntN(array_value('total_tax_price', $req_document_detail));
        //     $document_detail->total_zeikomi_price =  parseIntN(array_value('total_zeikomi_price', $req_document_detail));

        //     $document_detail->document_detail_data = [
        //         'comment' =>  array_value('comment', $req_document_detail)
        //         , 'unit' =>  array_value('unit', $req_document_detail)
        //     ];

        //     $document_detail->create_user = Admin::user()->name;
        //     $document_detail->update_user = Admin::user()->name;
        //     $document->document_details->add($document_detail);
        // }

        $service = new DocumentPdfService($request, $document);
        $service->makeContractPdf();

        $document_attachment_file = $service->getPdfPath();
        // save pdf
        $this->savePdfInServer($document_attachment_file, $service);
        $value['filepath'] = $document_attachment_file;
        $document->value = $value;
        // database save
        DB::beginTransaction();
        try
        {
            $document->saveOrFail();
            // $document->document_details()->saveMany($document->document_details);
            // // create relation
            // DB::table('exm_document_relations')->insert([
            //     'document_id' => $document->id
            //     , 'relation_type' => 'contracts'
            //     , 'relation_id' => $request->input('relation_id')
            //     , 'create_user' => Admin::user()->name
            //     , 'update_user' => Admin::user()->name
            //     ]);

            // foreach ($document->document_details as $document_detail)
            // {
            //     // create relation
            //     DB::table('exm_document_detail_relations')->insert([
            //         'document_detail_id' => $document_detail->id
            //         , 'relation_type' => 'contract_details'
            //         , 'relation_id' => $document_detail->relation_id
            //         , 'create_user' => Admin::user()->name
            //         , 'update_user' => Admin::user()->name
            //         ]);
            // }


            DB::commit();
        }
        catch (Exception $exception)
        {
            DB::rollback();
            throw $exception;
        }

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_base_path($this->custom_table->table_name . '/'.$id.'/edit'));
    }

    /**
     * Documentモデル作成
     * @param Request $request
     * @param mixed $id
     */
    protected function createDocumentModel(Request $request, $id, string $document_type){
        // Documentクラス作成
        // TODO:ハードコーディング
        $contract = getModelName('contract')::find($id);
        $base_info = getModelName(Define::SYSTEM_TABLE_NAME_BASEINFO)::first();
        $client = getValue($contract, 'client');
        // $agency = $contract->agency;
        // $isAgency = !is_null($agency) && $agency->document_target_flg;
        $isAgency = false;

        $document = [];
        $document['parent_id'] = isset($contract) ? $contract->id : null;
        $document['client_id'] = isset($client) ? $client->id : null;;
        $document['document_code'] = 'D-'.date('YmdHis');//TODO
        $document['document_edaban'] = 1; //TODO
        $document['zeinuki_price'] = getValue($contract, 'zeinuki_price');
        $document['tax_rate'] = getValue($contract, 'tax_rate');
        $document['tax_price'] = getValue($contract, 'tax_price');
        $document['zeikomi_price'] = getValue($contract, 'zeikomi_price');

        // 代理店がいる場合、書類送付の対象
        //if($isAgency){
        //    $document['to_company_name'] = $agency->agency_name;
        //    $document['to_contact_name'] = $agency->contact_name;
        //    $document['to_tel'] = $agency->contact_tel01.'-'.$agency->contact_tel02.'-'.$agency->contact_tel03;
        //    $document['to_mail'] = $agency->contact_mail;
        //}else
        {
            $document['to_company_name'] = getValue($client, 'client_name');
            $document['to_contact_name'] = getValue($client, 'contact_name');
            $document['to_tel'] = getValue($client, 'contact_tel01').'-'.getValue($client, 'contact_tel02').'-'.getValue($client, 'contact_tel03');
            $document['to_mail'] = getValue($client, 'contact_mail');
        }
        $document['to_company_honorific'] = '御中';
        $document['to_contact_honorific'] = '様';
        if($document_type == 'estimate'){
            $document['document_type'] = 'estimate';
            $document['title'] = '御見積書';
            $document['document_date'] = !is_null(getValue($contract, 'mitumorisyo_date')) ? \Carbon\Carbon::parse(getValue($contract, 'mitumorisyo_date')) : Carbon::today();
        }else if($document_type == 'invoice'){
            $document['document_type'] = 'invoice';
            $document['title'] = '御請求書';
            $document['document_date'] = !is_null(getValue($contract, 'seikyusyo_date')) ? \Carbon\Carbon::parse(getValue($contract, 'seikyusyo_date')) : Carbon::today();
        }else if($document_type == 'order'){
            $document['document_type'] = 'order';
            $document['title'] = '御注文書';
            $document['document_date'] = Carbon::today();
        }else if($document_type == 'delivery'){
            $document['document_type'] = 'delivery';
            $document['title'] = '納品書';
            $document['document_date'] = Carbon::today();
        }
        $document['document_limit_date'] = $document['document_date']->copy()->addMonth(1)->addDay(-1);

        $document['from_company_name'] = getValue($base_info, 'company_name');
        //$document['from_contact_name'] = $base_info, 'contact_name;
        $document['from_zip'] = getValue($base_info, 'zip01') . '-' . getValue($base_info, 'zip02');
        $document['from_addr01'] = getValue($base_info, 'pref_name').getValue($base_info, 'addr01');
        $document['from_addr02'] = getValue($base_info, 'addr02');
        $document['from_tel'] = getValue($base_info, 'tel01').'-'.getValue($base_info, 'tel02').'-'.getValue($base_info, 'tel03');
        $document['from_fax'] = getValue($base_info, 'fax01').'-'.getValue($base_info, 'fax02').'-'.getValue($base_info, 'fax03');
        $document['from_mail'] = getValue($base_info, 'mail');
        $document['from_user'] = Admin::user()->name;

        // if agency, add end-user info
        if($isAgency){
            $end_user = "＜エンドユーザー様情報＞";
            $end_user .= "\r\n会社名：".getValue($client, 'client_name');
            $end_user .= "\r\n部署名：".getValue($client, 'contact_department');
            $end_user .= "\r\nご担当者氏名：".getValue($client, 'contact_name')." 様";
            $end_user .= "\r\n住所：".getValue($client, 'client_zip01')."-" . getValue($client, 'zip02')." ".getValue($client, 'pref_name').getValue($client, 'addr01')." ".getValue($client, 'addr02');
            $end_user .= "\r\n電話番号：".getValue($client, 'contact_tel01')."-".getValue($client, 'contact_tel02')."-".getValue($client, 'contact_tel03');
            $end_user .= "\r\nFAX番号：".(!is_null(getValue($client, 'contact_fax01')) ? getValue($client, 'contact_fax01')."-".getValue($client, 'contact_fax02')."-".getValue($client, 'contact_fax03') : "");
            $end_user .= "\r\nメールアドレス：".getValue($client, 'contact_mail');
            $document['comment'] = $end_user;
        }else if($document_type == 'estimate'){
            // Footer Comment
            $document['comment'] = "*本見積書に記載の価格は弊社からの販売価格のため、弊社にご発注いただいた場合のみ適用されます。\r\n*本見積書の有効期限内でも、販売終息、価格変更、キャンペーン終了、その他の理由によりご注文いただけない場合があります。";
        }
        else if($document_type == 'invoice'){
        }

        if($document_type == 'estimate'){
            // Header Comment
            $document['header_comment'] = "下記の通りお見積いたします。";
        }
        else if($document_type == 'invoice'){
            // Header Comment
            $document['header_comment'] = "下記の通りご請求申し上げます。";
        }
        else if($document_type == 'delivery'){
            // Header Comment
            $document['header_comment'] = "下記の通りご納品申し上げます。";
        }

        $document['create_user'] = Admin::user()->name;
        $document['update_user'] = Admin::user()->name;

        $comment_count = 0;
        // 詳細も作成
        $document['document_details'] = [];
        $details = getChildrenValues($contract, 'contract_detail') ?? [];
        $model_detail = getModelName('document_detail');
        foreach ($details as $contract_detail)
        {
            $product_version = getValue($contract_detail, 'product_version_id');
            if(isset($product_version)){
                // get parent value
                $product = getParentValue($product_version);
            }
            // 保守更新の製品である場合、continue
            if($contract_detail->plan_type != 'subscription_update'){
                $document_detail = new $model_detail;
                $document_detail->relation_id =  getValue($contract_detail, 'id');
                $document_detail->name =  getValue($product, 'product_name').' '.getValue($product_version, 'product_version_name');
                $document_detail->fixed_price =  getValue($contract_detail, 'fixed_price');
                $document_detail->zeinuki_price =  getValue($contract_detail, 'zeinuki_price');
                $document_detail->wholecontract_rate =  getValue($contract_detail, 'wholecontract_rate');
                $document_detail->tax_rate =  getValue($contract_detail, 'tax_rate');
                $document_detail->tax_price =  getValue($contract_detail, 'tax_price');
                $document_detail->zeikomi_price =  getValue($contract_detail, 'zeikomi_price');
                $document_detail->num =  getValue($contract_detail, 'num');
                $document_detail->unit =  '式';
                $document_detail->total_zeinuki_price =  getValue($contract_detail, 'sum_zeinuki_price');
                $document_detail->total_tax_price =  getValue($contract_detail, 'sum_tax_price');
                $document_detail->total_zeikomi_price =  getValue($contract_detail, 'sum_zeikomi_price');
                $document_detail->create_user = Admin::user()->name;
                $document_detail->update_user = Admin::user()->name;
                $document['document_details'][] = $document_detail;
            }

            // サポートが存在する場合
            if(!is_null($contract_detail->product_version_support_id)){
                $document_detail = new $model_detail;
                $document_detail->relation_id =  getValue($contract_detail, 'id');
                $document_detail->name =  getValue($contract_detail, 'product_name').' '.getValue($contract_detail, 'product_version_name').' '.getValue($contract_detail, 'support_name');
                $document_detail->fixed_price =  getValue($contract_detail, 'support_fixed_price');
                $document_detail->zeinuki_price =  getValue($contract_detail, 'support_zeinuki_price');
                //$document_detail->wholecontract_rate =  $contract_detail->wholecontract_rate;//TODO
                $document_detail->tax_rate =  getValue($contract_detail, 'support_tax_rate');
                $document_detail->tax_price =  getValue($contract_detail, 'support_tax_price');
                $document_detail->zeikomi_price =  getValue($contract_detail, 'support_zeikomi_price');
                $document_detail->num =  getValue($contract_detail, 'num');
                $document_detail->unit =  '式';//Model\Define::SUPPORT_TYPE_UNIT[$contract_detail->support_type];
                $document_detail->total_zeinuki_price =  getValue($contract_detail, 'support_sum_zeinuki_price');
                $document_detail->total_tax_price =  getValue($contract_detail, 'support_sum_tax_price');
                $document_detail->total_zeikomi_price =  getValue($contract_detail, 'support_sum_zeikomi_price');
                $document_detail->comment = '※'. ++$comment_count;
                $document_detail->create_user = Admin::user()->name;
                $document_detail->update_user = Admin::user()->name;
                $document['document_details'][] = $document_detail;
            }
        }

        // add footer comment
        if($comment_count > 0){
            for ($i = 1; $i <= $comment_count; $i++)
            {
                $document->comment .= "\r\n". "※{$i}保守費用はソフトウェア本体価格の10%。保守期間はライセンス発行より一年間有効。"; //TODO：正確に
            }
        }

        return $document;
    }

    private function getLabel(string $document_type){
        $labels = [];
        switch($document_type){
            case 'estimate':
                $labels['document_code_label'] = '御見積番号';
                $labels['total_price_label'] = '御見積金額';
                $labels['document_date_label'] = '発行日';
                $labels['document_limit_date_label'] = '御見積有効期限';
                break;
            case 'invoice':
                $labels['document_code_label'] = '御請求番号';
                $labels['total_price_label'] = '御請求金額';
                $labels['document_date_label'] = '発行日';
                $labels['document_limit_date_label'] = 'お支払期限';
                break;
            case 'order':
                $labels['document_code_label'] = '御注文番号';
                $labels['total_price_label'] = '御注文金額';
                $labels['document_date_label'] = '発行日';
                break;
            case 'delivery':
                $labels['document_code_label'] = '納品番号';
                $labels['total_price_label'] = '納品金額';
                $labels['document_date_label'] = '納品日';
                break;
        }
        return $labels;
    }

    protected function getAgencyContact($client){
        $html = "＜お客様情報＞\r\n";
        $html .= getValue($client, 'client_name')."\r\n";
        $html .= getValue($client, 'contact_name')."\r\n";
        $html .= getValue($client, 'contact_tel01').'-'.getValue($client, 'contact_tel02').'-'.getValue($client, 'contact_tel03')."\r\n";
        $html .= getValue($client, 'contact_mail');

        return $html;
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
    
}

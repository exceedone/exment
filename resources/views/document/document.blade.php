<link rel="stylesheet" type="text/css" href="{{$css}}" />

{!!Form::model($document, ['pjax-container']) !!}
{!!Form::hidden("relation_id") !!}
{!!Form::hidden("client_id") !!}
{!!Form::hidden("document_type") !!}
{!!Form::hidden("document_edaban") !!}

<div class="document_area">
    <h2 class="header_title">{!!Form::text('title') !!}</h2>

    <div class="header_company_price">
        <div class="header_company row">
            <div class="col-sm-6">
                {!!Form::text('to_company_name', null, ['class' => 'to_company_name']) !!}
            </div>
            <div class="col-sm-2">
                {!!Form::text('to_company_honorific') !!}
            </div>
        </div>
        <div class="row">
            <div class="header_total_price form-group row col-sm-3">
                <div class="col-sm-8">
                    {!!Form::text('zeikomi_price', number_format(array_get($document, 'zeikomi_price')), ['readonly' => true, 'data-contract-summary'=> true, 'class' => 'zeikomi_price']) !!}
                </div>
                <div class="col-sm-4 control-label">
                    税込
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- 送付先 --}}
        <div class="col-xs-6">
            <div class="form-group">
                <div class="col-sm-3 control-label">
                    ご担当者名 :
                </div>
                <div class="col-sm-7">
                    {!!Form::text('to_contact_name', null, ['class' => 'to_contact_name']) !!}
                </div>
                <div class="col-sm-2 bold">
                    {!!Form::text('to_contact_honorific', null, ['class' => 'to_contact_honorific']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="small col-sm-3 control-label">
                    メールアドレス :
                </div>
                <div class="col-sm-7">
                    {!!Form::text('to_mail', null, ['class' => 'to_mail']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-3 control-label">
                    電話番号 :
                </div>
                <div class="col-sm-7">
                    {!!Form::text('to_tel', null, ['class' => 'to_tel']) !!}
                </div>
            </div>
            @if(array_key_exists('document_limit_date_label', $document_labels))
            <div class="form-group">
                <div class="small col-sm-3 control-label">
                    {{ $document_labels['document_limit_date_label'] }} :
                </div>
                <div class="col-sm-7">
                    {!!form::text('document_limit_date', date('Y-m-d', strtotime(array_get($document, 'document_limit_date')))) !!}
                </div>
            </div>
            @endif
        </div>

        {{-- 自分の情報 --}}
        <div class="col-xs-6 from-info">

            <div class="form-group">
                <div class="col-sm-4 control-label">
                    {{ $document_labels['document_code_label'] }} :
                </div>
                <div class="col-sm-6">
                    {!!form::text('document_code', null, ['class' => 'document_code']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    {{ $document_labels['document_date_label'] }} :
                </div>
                <div class="col-sm-6">
                    {!!form::text('document_date', date('Y-m-d', strtotime(array_get($document, 'document_date')))) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    自社名 :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_company_name', null, ['class' => 'from_company_name']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    〒 :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_zip', null, ['class' => 'from_zip']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    住所 :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_addr01', null, ['class' => 'from_addr01']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="small col-sm-4 control-label">
                    ビル・部屋番号など :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_addr02', null, ['class' => 'from_addr02']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="small col-sm-4 control-label">
                    メールアドレス :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_mail', null, ['class' => 'from_mail']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    電話番号 :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_tel', null, ['class' => 'from_tel']) !!}
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-4 control-label">
                    FAX番号 :
                </div>
                <div class="col-sm-8">
                    {!!form::text('from_fax', null, ['class' => 'from_fax']) !!}
                </div>
            </div>
        </div>
    </div>

    {{-- 見積表 --}}
    <div class="row header_comment">
        <div class="col-sm-6">
            {!!Form::text('header_comment') !!}
        </div>
    </div>
    <table class="table table-document-details">
        <thead>
            <tr>
                <td class="button_column"></td>
                <th class="col-sm-5">品名</th>
                <th class="col-sm-1">標準単価</th>
                <th class="col-sm-1">販売単価</th>
                <th class="col-sm-1">数量</th>
                <th class="col-sm-1">単位</th>
                <th class="col-sm-1">小計(税抜)</th>
                <th class="col-sm-2">備考</th>
            </tr>
        </thead>
        <tbody>
            @foreach(array_get($document, 'document_details') as $document_detail)
            <tr class="row-document-detail">
                <td class="button_column">
                    <div class="button_area">
                        <button type="button" class="btn btn-default btn-sm removebutton"><i class="fa fa-close"></i></button>
                    </div>
                </td>
                <td class="col-sm-5">
                    {!!Form::hidden("document_details[$loop->index][id]", $document_detail->id) !!}
                    {!!Form::text("document_details[$loop->index][name]", $document_detail->name, ['class' => 'name']) !!}
                </td>
                <td class="col-sm-1">{!!Form::text("document_details[$loop->index][fixed_price]", number_format($document_detail->fixed_price), ['number_format', 'class' => 'fixed_price']) !!}</td>
                <td class="col-sm-1">{!!Form::text("document_details[$loop->index][zeinuki_price]", number_format($document_detail->zeinuki_price), ['number_format', 'class' => 'zeinuki_price']) !!}</td>
                <td class="col-sm-1">{!!Form::text("document_details[$loop->index][num]", number_format($document_detail->num), ['number_format', 'class' => 'num']) !!}</td>
                <td class="col-sm-1">{!!Form::text("document_details[$loop->index][unit]", $document_detail->unit, ['class' => 'unit']) !!}</td>
                <td class="col-sm-1">
                    {!!Form::text("document_details[$loop->index][total_zeinuki_price]", number_format($document_detail->total_zeinuki_price), ['number_format', 'readonly' => true, 'class' => 'total_zeinuki_price']) !!}

                    {!!Form::hidden("document_details[$loop->index][zeikomi_price]", $document_detail->zeikomi_price, ['class' => 'zeikomi_price']) !!}
                    {!!Form::hidden("document_details[$loop->index][tax_price]", $document_detail->tax_price, ['class' => 'tax_price']) !!}
                    {!!Form::hidden("document_details[$loop->index][tax_rate]", $document_detail->tax_rate, ['class' => 'tax_rate']) !!}
                    {!!Form::hidden("document_details[$loop->index][total_zeikomi_price]", $document_detail->total_zeikomi_price, ['class' => 'total_zeikomi_price']) !!}
                    {!!Form::hidden("document_details[$loop->index][total_tax_price]", $document_detail->total_tax_price, ['class' => 'total_tax_price']) !!}
                    {!!Form::hidden("document_details[$loop->index][relation_id]", $document_detail->relation_id, ['class' => 'relation_id']) !!}
                    {!!Form::hidden("document_details[$loop->index][_file_del_]", '', ['class' => '_file_del_']) !!}
                </td>
                <td class="col-sm-4">
                    {!!Form::text("document_details[$loop->index][comment]", $document_detail->comment, ['class' => 'comment']) !!}
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="button_column"></td>
                <td rowspan="2" class="bordernone col-sm-5">
                    <button type="button" class="btn btn-xs btn-default addbutton">
                        <i class="fa fa-plus" aria-hidden="true"></i>
                        &nbsp;行追加
                    </button>
                </td>
                <th colspan="4" class="text-right col-sm-8">商品小計(税抜)</th>
                <td class="col-sm-1">{!!Form::text('zeinuki_price', number_format(array_get($document, 'zeinuki_price')), ['number_format', 'readonly' => true, 'data-contract-summary' => true, 'class' => 'zeinuki_price']) !!}</td>
                <td class="bordernone col-sm-4">&nbsp;</td>
            </tr>
            <tr>
                <td class="button_column"></td>
                <th colspan="4" class="text-right col-sm-8">消費税</th>
                <td class="col-sm-1">{!!Form::text('tax_price', number_format(array_get($document, 'tax_price')), ['number_format', 'readonly' => true, 'data-contract-summary'=> true, 'class' => 'tax_price']) !!}</td>
                <td class="bordernone col-sm-4">&nbsp;</td>
            </tr>
            <tr>
                <td class="button_column"></td>
                <td class="bordernone col-sm-5">&nbsp;</td>
                <th colspan="4" class="text-right col-sm-8">{{ $document_labels['total_price_label'] }}</th>
                <td class="col-sm-1">{!!Form::text('zeikomi_price', number_format(array_get($document, 'zeikomi_price')), ['number_format', 'readonly' => true, 'data-contract-summary'=> true, 'class' => 'zeikomi_price']) !!}</td>
                <td class="bordernone col-sm-4">&nbsp;</td>
            </tr>
        </tfoot>
    </table>

    {{-- 備考 --}}
    <div>
        <p>備考</p>
        {!!Form::textarea('comment', null, ['rows' => 8]) !!}
    </div>

    <div class="form-footer">
        <!--<h3>データ保存時の注意</h3>
        <ul>
            <li>データ保存時、見積書のPDFファイルが自動的に作成されます。</li>
            <li>再度データを保存時、見積書のPDFファイルは上書き保存されます。</li>
        </ul>-->
        {!!Form::token() !!}
        <div class="clearfix">
            <div class="col-md-11"></div>
            <div class="col-md-1">
                <div class="btn-group pull-right">
                    <button type="submit" class="btn btn-info pull-right" data-loading-text="<i class='fa fa-spinner fa-spin '></i> 保存">保存</button>
                </div>
            </div>
        </div>
    </div>
</div>
{!!Form::close() !!}

<script src="/vendor/laravel-admin/ex1/js/document.js"></script>
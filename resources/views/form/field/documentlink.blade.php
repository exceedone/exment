{!! $document->getUrl(true) !!}
&nbsp;
<small>
    ({{exmtrans("common.created_at")}}&nbsp;:&nbsp;{{$document->created_at}}
    &nbsp;{{ exmtrans("common.created_user") }}&nbsp;:&nbsp;{{ $document->created_user }})
</small>

<a href="javascript:void(0);" data-exment-delete="{{admin_urls('files', $document->getValue('file_uuid'))}}">
    <i class="fa fa-trash"></i>
</a>
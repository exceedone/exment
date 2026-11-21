{!! $document->tag_url !!}
&nbsp;
<small>
    ({{exmtrans("common.created_at")}}&nbsp;:&nbsp;{{$document->created_at}}
    &nbsp;{{ exmtrans("common.created_user") }}&nbsp;:&nbsp;{{ $document->created_user }})
</small>

@if(($version_no ?? 1) > 1 && !empty($versions_url))
<a href="javascript:void(0);" class="small" data-exment-document-versions data-url="{{ $versions_url }}" data-bs-toggle='tooltip' title="{{ exmtrans('custom_value.document_versions') }}">
    <i class="fa fa-history"></i>&nbsp;{{ exmtrans('custom_value.document_version_no', ['no' => $version_no]) }}
</a>
@endif

@if(!empty($canreplace) && !empty($replace_url) && (!boolval(config('exment.file_delete_useronly', false)) || $document->created_user_id == \Exment::getUserId()))
<a href="javascript:void(0);" data-exment-document-replace data-bs-toggle='tooltip' title="{{ exmtrans('custom_value.document_replace_help') }}">
    <i class="fa fa-upload"></i>
</a>
<input type="file" class="exment-document-replace-file d-none" style="display:none" data-url="{{ $replace_url }}">
@endif

@if($candelete && (!boolval(config('exment.file_delete_useronly', false)) || $document->created_user_id == \Exment::getUserId()))
<a href="javascript:void(0);" data-exment-delete="{{ $document->url }}" data-bs-toggle='tooltip' title="{{trans('admin.delete')}}">
    <i class="fa fa-trash"></i>
</a>
@endif

@if(!empty($versions_url))
<span class="exment-document-versions d-block ms-3" style="display:none"></span>
@endif

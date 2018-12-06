<p style="padding-top:7px;">
<a href="{{$link}}">
    {{$revision->updated_at}}
</a>
    <small>
        &nbsp;({{ exmtrans("common.updated_user") }}：{{ $revision->user }})
        &nbsp;{{$index == 0 ? '最新' : ''}}
    </small>
</p>
<p style="padding-top:7px;">
<a href="{{$link}}">
    {{$revision->updated_at}}
</a>
    <small>
        &nbsp;({{ exmtrans("common.updated_user") }}&nbsp;:&nbsp;{{ $revision->user }})
        &nbsp;{{$index == 0 ? exmtrans('revision.new') : ''}}
    </small>
</p>
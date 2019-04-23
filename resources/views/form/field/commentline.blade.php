<small>
    {{$comment->created_user}}
    &nbsp;{{ $comment->created_at }}
</small>

&nbsp;

@if($isAbleRemove)
<a href="javascript:void(0);" data-exment-delete="{{admin_urls($table_name, 'comments', $comment->id)}}">
    <i class="fa fa-trash"></i>
</a>
@endif

{!! replaceBreak($comment->getLabel()) !!}
<hr />
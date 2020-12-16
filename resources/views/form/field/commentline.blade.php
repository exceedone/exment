<p class="commentline_header">
<small>
    {!! $comment->created_user_avatar !!}
    &nbsp;{{ $comment->created_at }}
</small>

&nbsp;

@if($isAbleRemove)
<a href="javascript:void(0);" data-exment-delete="{{$deleteUrl}}">
    <i class="fa fa-trash"></i>
</a>
@endif
</p>

<p class="commentline_inner">
{!! replaceBreakEsc($comment->getLabel()) !!}
</p>
<hr />
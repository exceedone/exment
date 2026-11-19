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

{{-- Markdown; html of the comment is escaped and the result is cleaned (CommentMarkdown) --}}
<div class="commentline_inner comment-markdown">
{!! \Exceedone\Exment\Services\CommentMarkdown::toHtml($comment->getValue('comment_detail')) !!}
</div>
<hr />
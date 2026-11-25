<p class="commentline_header">
<small>
    {!! $comment->created_user_avatar !!}
    &nbsp;{{ \Exceedone\Exment\Services\UserTimezone::display($comment->created_at) }}
</small>

&nbsp;

@if($isAbleRemove)
<a href="javascript:void(0);" data-exment-delete="{{$deleteUrl}}">
    <i class="fa fa-trash"></i>
</a>
@endif
</p>

{{-- Markdown, or plain text for a comment written before Markdown comments came; always escaped and cleaned (CommentMarkdown) --}}
<div class="commentline_inner comment-markdown">
{!! \Exceedone\Exment\Services\CommentMarkdown::commentHtml($comment) !!}
</div>
<hr />
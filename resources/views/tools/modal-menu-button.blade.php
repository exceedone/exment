<a class="btn btn-sm {{$button_class}} pull-right" style="margin-right:5px;"
    data-widgetmodal_url="{{$ajax}}"
    data-widgetmodal_method="GET"
    data-widgetmodal_expand='{{$expand}}'
    data-widgetmodal_uuid='{{$uuid}}'
    data-widgetmodal_html='{{isset($html)}}'
>
    <i class="fa {{$icon}}"></i>
    <span class="hidden-xs">&nbsp;{{$label}}</span>
</a>

@if(isset($html))
<div class="widgetmodal_html" data-widgetmodal_title='{{$modal_title ?? null}}' data-widgetmodal_html_target='{{$uuid}}' style="display:none;">
    {!! $html !!}
</div>
@endif
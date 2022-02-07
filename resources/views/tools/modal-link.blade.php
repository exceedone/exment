<a href="javascript::void(0);" class="{{$link_class}}" 
    title="{{$modal_title}}"
    data-widgetmodal_url="{{$ajax}}"
    data-widgetmodal_method="GET"
    data-widgetmodal_expand='{{$expand}}'
    data-widgetmodal_uuid='{{$uuid}}'
    data-widgetmodal_html='{{isset($html)}}'
    {!! $attributes !!}
>

@if(isset($icon))
<i class="fa {{$icon}}"></i>
@else
{{ $label }}
@endif
</a>

@if(isset($html))
<div class="widgetmodal_html" data-widgetmodal_title='{{$modal_title ?? null}}' data-widgetmodal_html_target='{{$uuid}}' style="display:none;">
    {!! $html !!}
</div>
@endif
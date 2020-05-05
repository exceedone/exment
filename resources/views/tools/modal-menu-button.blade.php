<div class="btn-group pull-right">
<a class="btn btn-sm {{$button_class}} {{!is_nullorempty($menulist) ? 'dropdown-toggle' : ''}}" 
    style="margin-right:5px;"
    data-widgetmodal_url="{{$ajax}}"
    data-widgetmodal_method="GET"
    data-widgetmodal_expand='{{$expand}}'
    data-widgetmodal_uuid='{{$uuid}}'
    data-widgetmodal_html='{{isset($html) && is_nullorempty($menulist)}}'
    {!! $attributes !!}
>
    <i class="fa {{$icon}}"></i>
    <span class="hidden-xs">&nbsp;{{$label}}</span>

    @if(!is_nullorempty($menulist))
    <span class="caret"></span>
    @endif
</a>

@if(!is_nullorempty($menulist))
<ul class="dropdown-menu">
    @foreach($menulist as $menu)
        @if(boolval(array_get($menu, 'header')))
        <li class="dropdown-header">
            {{array_get($menu, 'label')}}
        </li>
        @else
        <li>
            <a href="{{ array_get($menu, 'url', 'javascript:void(0);') }}" 
                data-widgetmodal_url="{{ array_get($menu, 'ajax') }}"
                data-widgetmodal_method="GET"
                data-widgetmodal_uuid='{{$uuid}}'
                data-widgetmodal_html="{{boolval(array_get($menu, 'isHtml'))}}"
            >
                @if(!is_null(array_get($menu, 'icon')))
                <i class="fa {{ array_get($menu, 'icon') }}"></i>
                @endif
                &nbsp;{{array_get($menu, 'label')}}
            </a>
        </li>
        @endif
    @endforeach
</ul>
@endif

@if(isset($html))
<div class="widgetmodal_html" data-widgetmodal_title='{{$modal_title ?? null}}' data-widgetmodal_html_target='{{$uuid}}' style="display:none;">
    {!! $html !!}
</div>
@endif

</div>
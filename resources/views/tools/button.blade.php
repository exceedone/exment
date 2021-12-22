<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{{ $href }}" class="btn btn-sm {{ $btn_class ?? 'btn-default' }}" title="{{ $label }}" target="{{$target ?? '_self'}}" {!! isset($attributes) ? \Exment::formatAttributes($attributes) : '' !!}>
        <i class="fa {{ $icon }}"></i><span class="hidden-xs">&nbsp;{{ $label }}</span>
    </a>
</div>
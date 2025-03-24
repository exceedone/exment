<div class="btn-group float-end p-0" style="margin-right: 5px">
    <a href="{{ $href }}" class="btn btn-sm {{ $btn_class ?? 'btn-default' }}" title="{{ $label }}" target="{{$target ?? '_self'}}" {!! isset($attributes) ? \Exment::formatAttributes($attributes) : '' !!}>
        <i class="fa {{ $icon }}"></i><span class="d-none d-md-inline">&nbsp;{{ $label }}</span>
    </a>
</div>
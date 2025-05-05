<div class="btn-group float-end p-0" style="margin-right: 5px">
    <a href="{{ $href }}" class="btn btn-sm {{ $btn_class ?? 'btn-default' }} d-flex align-items-center p-2 text-white" title="{{ $label }}" target="{{$target ?? '_self'}}" {!! isset($attributes) ? \Exment::formatAttributes($attributes) : '' !!}>
        <i class="fa {{ $icon }} p-1"></i><span class="d-none d-md-inline">&nbsp;{{ $label }}</span>
    </a>
</div>
<a href="{{ $href }}" class="btn btn-sm {{ $btn_class ?? 'btn-default' }}" title="{{ $label }}" target="{{$target ?? '_self'}}">
    @if(isset($icon))
    <i class="fa {{ $icon }}"></i>
    @endif
    <span class="hidden-xs">&nbsp;{{ $label }}</span>
</a>
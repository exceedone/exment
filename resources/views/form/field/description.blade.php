<div class="form-group">
    <div class="{{$viewClass['field']}} {{$offset}} mx-auto p-2" {!! $attributes !!} >
        @if($escape)
        {{ $label }}
        @else
        {!! html_clean($label) !!}
        @endif
    </div>
</div>
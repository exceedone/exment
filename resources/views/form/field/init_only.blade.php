@php
    // Array-safe rendering: multi-value columns (select / select_table / user / organization /
    // file / image with multiple_enabled) provide an array. Blade {{ }} -> e() -> htmlspecialchars()
    // throws a TypeError on PHP 8 when given an array, so normalise before echoing.
    $displayTextSafe = (isset($displayText) && is_array($displayText)) ? implode(', ', \Illuminate\Support\Arr::flatten($displayText)) : ($displayText ?? null);
    $valueSafe = is_array($value) ? implode(', ', \Illuminate\Support\Arr::flatten($value)) : $value;
@endphp
<div class="{{$viewClass['form-group']}}">
    <label class="{{$viewClass['label']}} control-label" style="padding-top:10px;">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        <div class="no-margin">
            @if($prepareDefault)
                @if(is_array($default))
                    @foreach(\Illuminate\Support\Arr::flatten($default) as $defaultValue)
                    <input type="hidden" name="{{$name}}[]" value="{{ $defaultValue }}" class="{{$class}}" data-disable-setvalue="1" />
                    @endforeach
                @else
                <input type="hidden" name="{{$name}}" value="{{$default}}" class="{{$class}}" data-disable-setvalue="1" />
                @endif
            @endif
            <!-- /.box-header -->
            <div class="box-body {{$displayClass ?? null}}" style="padding-left:0; padding-bottom:0;">
                <span class="{{$class}}" {!! $attributes  !!}>
                @if(isset($displayText))
                    @if(!$escape)
                    {!! $displayTextSafe !!}
                    @else
                    {{ $displayTextSafe }}
                    @endif
                @else
                    @if(!$escape)
                    {!! $valueSafe !!}
                    @else
                    {{ $valueSafe }}
                    @endif
                @endif
                </span>&nbsp;
            </div><!-- /.box-body -->
        </div>

        @include('admin::form.help-block')

    </div>
</div>
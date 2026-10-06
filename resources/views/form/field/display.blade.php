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
                    {{-- No prepared display text: $value is the raw stored (or re-submitted) value, so it is
                         always escaped. $escape only applies to $displayText, which the caller built as safe HTML. --}}
                    {{ $valueSafe }}
                @endif
                </span>
                {{-- Hidden input to save value to database (array-safe for multi-value columns) --}}
                @if(is_array($value))
                    @foreach(\Illuminate\Support\Arr::flatten($value) as $hiddenValue)
                    <input type="hidden" name="{{$name}}[]" value="{{ $hiddenValue }}" />
                    @endforeach
                @else
                    <input type="hidden" name="{{$name}}" value="{{ $value }}" />
                @endif
            </div><!-- /.box-body -->
        </div>

        @include('admin::form.help-block')

    </div>
</div>
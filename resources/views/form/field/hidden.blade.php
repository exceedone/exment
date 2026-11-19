{{-- Array-safe hidden input: multi-value columns (select / select_table / user / organization /
     file / image with multiple_enabled) provide an array. Blade {{ }} -> e() -> htmlspecialchars()
     throws a TypeError on PHP 8 when given an array, so each value gets an input of its own,
     named "...[]" like a multiple select, and the values are posted back as they were. --}}
@if(is_array($old))
    @foreach(\Illuminate\Support\Arr::flatten($old) as $hiddenValue)
    <input type="hidden" name="{{$name}}[]" value="{{ $hiddenValue }}" class="{{$class}}" {!! $attributes !!} />
    @endforeach
@else
<input type="hidden" name="{{$name}}" value="{{$old}}" class="{{$class}}" {!! $attributes !!} />
@endif

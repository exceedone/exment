<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label text-lg-end text-nowrap">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <input type="checkbox" class="{{$class}} la_checkbox" {{ $old == $onValue ? 'checked' : '' }} {!! $attributes !!} />
        <input type="hidden" class="{{$class}}" name="{{$name}}" value="{{ $old }}" />

        @include('admin::form.help-block')

    </div>
</div>

<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        @if($add_empty)
            {!! $inline ? '<span class="icheck">' : '<div class="radio icheck">'  !!}

                <label @if($inline)class="radio-inline"@endif>
                    <input type="radio" name="{{$name}}" value="" class="minimal {{$class}}" {{ $value === null ?'checked':'' }} {!! $attributes !!} />&nbsp;{{ exmtrans('common.no_selected') }}&nbsp;&nbsp;
                </label>

            {!! $inline ? '</span>' :  '</div>' !!}
        @endif

        @foreach($options as $option => $label)

            {!! $inline ? '<span class="icheck">' : '<div class="radio icheck">'  !!}

                <label @if($inline)class="radio-inline"@endif>
                    <input type="radio" name="{{$name}}" value="{{$option}}" class="minimal {{$class}}" {{ isMatchString($option, $old) || ($value === null && in_array($label, $checked)) ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$label}}&nbsp;&nbsp;
                </label>

            {!! $inline ? '</span>' :  '</div>' !!}

        @endforeach

        @include('admin::form.help-block')

    </div>
</div>

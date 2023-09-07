<div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}" id="{{$id}}">

        @include('admin::form.error')

            <div class="checkbox">
            <label class="checkboxone-label">
                <input type="hidden" name="{{$name}}">
                <input type="checkbox" name="{{$name}}" value="{{$check_value}}" class="{{$class}}" {{ isMatchString($check_value, $old) ?'checked':'' }} {!! $attributes !!} />&nbsp;{{$check_label}}&nbsp;&nbsp;
            </label>
            </div>

        @include('admin::form.help-block')

    </div>
</div>

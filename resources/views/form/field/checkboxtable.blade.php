<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        @foreach($options as $option => $label)
            <span class="icheck" style="width:{{$checkWidth}}px; display:inline-block; text-align:center;">
                <label class="checkbox-inline">
                    <input type="checkbox" name="{{$name}}[]" value="{{$option}}" class="{{$class}}" {{ in_array($option, (array)old($column, $value)) || ($value === null && in_array($label, $checked)) ?'checked':'' }} {!! $attributes !!} />
                </label>
            </span>

        @endforeach

        <input type="hidden" name="{{$name}}[]">

        @include('admin::form.help-block')

    </div>
</div>

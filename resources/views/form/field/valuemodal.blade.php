<div class="block-valuemodal">
    <div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

        <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

        <div class="{{$viewClass['field']}}" id="{{$id}}">
            @include('admin::form.error')

            <p style="padding-top:7px;" class="text-valuemodal">{{ $text }}</p>
            <p><button type="button" class="btn btn-default btn-valuemodal" 
            data-widgetmodal_url="{{$ajax}}"
            data-widgetmodal_method="POST"
            data-widgetmodal_getdata='["{{$modalContentname}}"]'
            >
            {{ $buttonlabel }}
            <input type="hidden" name="{{$name}}" value="{{$value}}" class="{{$class}}" {!! $attributes !!} />
            </button></p>

            @include('admin::form.help-block')
        </div>
    </div>
</div>

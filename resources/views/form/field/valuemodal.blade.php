<div class="block-valuemodal">
    <div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ?: 'has-error' !!}">

        <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

        <div class="{{$viewClass['field']}}" id="{{$id}}">
            @include('admin::form.error')

            <p style="padding-top:7px;" class="text-valuemodal">
                @if(is_nullorempty($text) && isset($nullText))
                    {{$nullText}}
                @else
                    {!! $text !!}
                @endif
            </p>
            <p><button type="button" class="btn {{$buttonClass}} btn-valuemodal" 
            data-widgetmodal_url="{{$ajax}}"
            data-widgetmodal_method="POST"
            data-widgetmodal_hasmany="1"
            data-widgetmodal_getdata='["{{$modalContentname}}"]'
            >
            {{ $buttonlabel }}
            <input type="hidden" name="{{$name}}" value="{{$hidden}}" class="{{$class}} value-valuemodal" {!! $attributes !!} />
            <input type="hidden" value="{{$nullText}}" class="nulltext-valuemodal" />
            <input type="hidden" value="{{$nullValue}}" class="nullvalue-valuemodal" />
            </button></p>

            @include('admin::form.help-block')
        </div>
    </div>
</div>

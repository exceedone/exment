<div class="block-valuemodal" data-valuemodal_uuid="{{$uuid}}">
    <div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

        <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

        <div class="{{$viewClass['field']}}" id="{{$id}}">
            @include('admin::form.error')

            <p style="padding-top:7px;" class="text-valuemodal">
                @if(is_nullorempty($text) && isset($nullText))
                    {{$nullText}}
                @elseif(is_array($text))
                @foreach($text as $t)
                    {{$t}}
                    @if(!$loop->last)
                    <br />
                    @endif
                @endforeach
                @else
                {{$text}}
                @endif
            </p>
            <p><button type="button" class="btn {{$buttonClass}} btn-valuemodal" 
            data-widgetmodal_url="{{$ajax}}"
            data-widgetmodal_method="POST"
            data-widgetmodal_getdata='["{{$modalContentname}}"]'
            data-widgetmodal_expand='{{$expand}}'
            >
            {{ $buttonlabel }}
            <input type="hidden" name="{{$name}}" value="{{$hidden}}" class="{{$class}} value-valuemodal" {!! $attributes !!} />
            <input type="hidden" value="{{$nullText}}" class="nulltext-valuemodal" />
            </button></p>

            @include('admin::form.help-block')
        </div>
    </div>
</div>

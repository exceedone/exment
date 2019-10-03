<div class="block-valuemodal">
    <div class="{{$viewClass['form-group']}} {!! !$errors->has($column) ?: 'has-error' !!}">

        <label for="{{$id}}" class="{{$viewClass['label']}} control-label">{{$label}}</label>

        <div class="{{$viewClass['field']}}" id="{{$id}}">
            @include('admin::form.error')

            <p style="padding-top:7px;" class="text-valuemodal">{{ $text }}</p>
            <p><button type="button" class="btn btn-default btn-valuemodal" {{ isset($ajax) ? 'data-' }}}>
            {{ $buttonlabel }}
            <input type="hidden" name="{{$name}}" value="{{$value}}" class="{{$class}}" {!! $attributes !!} />
            </button></p>

            @include('admin::form.help-block')
        </div>
    </div>

    <div class="modal fade">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>×</span></button>
                    <h4 class="modal-title" id="modal-label">{{$label}}</h4>
                </div>
                <div class="modal-body" id="modal-body">
                    {!! $modalbody !!}
                </div>
                @if(count($buttons) > 0)
                <div class="modal-footer">
                    <div class="col-sm-12">
                        @if(in_array('reset', $buttons))
                        <button type="button" class="btn btn-default button-reset">{{trans('admin.reset')}}</button> @endif 
                        @if(in_array('setting', $buttons))
                        <button type="button" class="btn btn-info button-setting">{{trans('admin.setting')}}</button> @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

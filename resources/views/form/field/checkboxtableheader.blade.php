<div class="{{$viewClass['form-group']}} {!! !$errors->has($errorKey) ? '' : 'has-error' !!}" style="margin-bottom:0;">

    <label for="{{$id}}" class="{{$viewClass['label']}} control-label">&nbsp;</label>

    <div class="{{$viewClass['field']}}">
        @foreach($options as $option => $label)
            <span style="width:{{$checkWidth}}px; display:inline-block; text-align:center; font-size:0.85em;">
                {{$label}}

                @if($loop->index < count($help) && !empty($help[$loop->index]))
                <br/>
                <i class="fa fa-info-circle" data-help-text="{{$help[$loop->index]}}" data-help-title="{{ $label }}"></i>
                @endif
            </span>
        @endforeach
    </div>
</div>

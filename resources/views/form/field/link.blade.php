<div class="form-group {!! !$errors->has($label) ?: 'has-error' !!}">
    <label for="{{$id}}" class="col-sm-2 control-label">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        @include('admin::form.error')

        <div id="{{$id}}" style="width: 100%; height: 100%;">
            @if(isset($value))
            <p>
                <a href="{!! $old !!}" class="{{ isset($button) ? 'btn '.$button : '' }}" target="{{ isset($target) ? $target : '_self' }}">
                    @if(isset($icon))
                    <i class="fa {{ $icon }}">{{ $text }}</i>
                    @endif
                </a>
            </p>
            @else
            <p style="margin-top:7px;">
                {{ $emptyText }}
            </p>
            @endif
        </div>
    </div>
</div>
<div class="{{$viewClass['form-group']}}">
    <label class="{{$viewClass['label']}} control-label" style="padding-top:10px;">{{$label}}</label>
    <div class="{{$viewClass['field']}}">
        <button type="button" class="{{$class}} ajax-btn btn {{ $button_class ?? 'btn-default' }}" data-default-label="{{ $button_label ?? trans('admin.submit') }}" style="margin-top:5px;" data-loading-label="loading..." {!! $attributes !!}>
            {{ $button_label ?? trans('admin.submit') }}
        </button>
        @include('admin::form.help-block')
    </div>
</div>
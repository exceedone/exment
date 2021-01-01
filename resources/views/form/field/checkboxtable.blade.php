
@if($hasError)
<div class="has-error form-group">
    <div class="col-sm-offset-2 col-sm-8 red">
        {{exmtrans('error.description')}}
    </div>
</div>
@endif

<div class="checkboxtable form-group">

<div class="{{$viewClass['label']}}">
    <div class="checkboxtable-header">
        &nbsp;
    </div>
@foreach($items as $item)
    <div class="checkboxtable-body text-right {{!is_nullorempty(array_get($item, 'error')) ? 'has-error' : ''}}">
        <label for="{{$id}}" class="control-label">{{$item['label']}}</label>

        @if(!is_nullorempty(array_get($item, 'error')))
        <i class="fa fa-exclamation-circle" data-help-text="{{array_get($item, 'error')}}" data-help-title="{{ exmtrans('common.error') }}"></i>
        @endif
    </div>
@endforeach
</div>

<div class="{{$viewClass['field']}}" style="overflow-y:hidden; overflow-x:auto; white-space: nowrap;">
    <div class="checkboxtable-header">
    @foreach($options as $option => $label)
    <span style="width:{{$checkWidth}}px; display:inline-block; text-align:center; font-size:0.85em;">
        {{$label}}

        @if(!empty($headerHelps[$option]))
        <br/>
        <i class="fa fa-info-circle" data-help-text="{{$headerHelps[$option]}}" data-help-title="{{ $label }}"></i>
        @endif
    </span>
    @endforeach
    </div>

@foreach($items as $item)
    <div class="checkboxtable-body">
        @foreach($options as $option => $label)
            <span class="icheck" style="width:{{$checkWidth}}px; display:inline-block; text-align:center;">
                <label class="checkbox-inline">
                    <input type="checkbox" name="{{$item['name']}}[]" value="{{$option}}" class="{{$class}}" {{ in_array($option, (array)old(array_get($item, 'key'), $item['values'])) || ($item['values'] === null && in_array($label, $checked)) ?'checked':'' }} 
                        {{ in_array($option, array_get($item, 'disables', [])) ? ' disabled' : '' }}  {!! $attributes !!} 
                    />
                </label>
            </span>
        @endforeach

        <input type="hidden" name="{{$item['name']}}[]">

        @include('admin::form.help-block')
    </div>
@endforeach
</div>

</div>
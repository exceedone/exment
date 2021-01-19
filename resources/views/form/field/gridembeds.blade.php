
@if($enableHeader)
<div class="">
    <h4 class="field-header">{{ $label }}</h4>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">

    <div class="embed-{{$column}}-forms">

        <div class="embed-{{$column}}-form fields-group">
            @foreach($fieldGroups as $fieldGroup)
                <div class="row">
                    @foreach($fieldGroup as $field)
                    <div class="col-sm-{{array_get($field, 'col_sm', 12)}}">
                    {!! $field['field']->render() !!}
                    </div>
                    @endforeach
                </div>
            @endforeach

        </div>
    </div>
</div>

@if($footer_hr)
<hr style="margin-top: 0px;">
@endif
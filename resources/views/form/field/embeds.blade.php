
@if($enableHeader)
<div class="">
    <h4 class="field-header px-5">{{ $label }}</h4>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">

    <div class="embed-{{$column}}-forms">

        <div class="embed-{{$column}}-form fields-group pt-3">

            @foreach($form->fields() as $field)
                {!! $field->render() !!}
            @endforeach

        </div>
    </div>
</div>

@if($footer_hr)
<hr style="margin-top: 0px;">
@endif
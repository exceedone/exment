
@if($enableHeader)
<div class="">
    <h4 class="field-header">{{ $label }}</h4>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">

    <div class="embed-{{$column}}-forms">

        <div class="embed-{{$column}}-form fields-group">
            @if(isset($is_grid))
                @if(isset($gridHeaders))
                    <div class="">
                    @foreach($gridHeaders as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                    <hr />
                @endif
                @if(isset($gridFieldsL) && isset($gridFieldsR))
                    <div class="">
                        <div class="col-xs-12 col-md-6">
                        @foreach($gridFieldsL as $field)
                            {!! $field->render() !!}
                        @endforeach
                        </div>
                        <div class="col-xs-12 col-md-6">
                        @foreach($gridFieldsR as $field)
                            {!! $field->render() !!}
                        @endforeach
                        </div>
                    </div>
                @elseif (isset($gridFieldsL))
                    <div class="">
                    @foreach($gridFieldsL as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @elseif (isset($gridFieldsR))
                    <div class="">
                    @foreach($gridFieldsR as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @endif

                @if(isset($gridFooters))
                    @if(isset($gridFieldsL) || isset($gridFieldsR))
                    <hr />
                    @endif
                    <div class="">
                    @foreach($gridFooters as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @endif
            @else
                @foreach($form->fields() as $field)
                    {!! $field->render() !!}
                @endforeach
            @endif
        </div>
    </div>
</div>

@if($footer_hr)
<hr style="margin-top: 0px;">
@endif
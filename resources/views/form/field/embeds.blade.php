
@if($header)
<div class="row">
    <div class="col-sm-12">
        <h4 class="field-header">{{ $label }}</h4>
    </div>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">

    <div class="embed-{{$column}}-forms">

        <div class="embed-{{$column}}-form fields-group">
            @if(isset($is_grid))
                @if(isset($gridHeaders))
                    <div class="row">
                    @foreach($gridHeaders as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                    <hr />
                @endif
                @if(isset($gridFieldsL) && isset($gridFieldsR))
                    <div class="row">
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
                    <div class="row">
                    @foreach($gridFieldsL as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @elseif (isset($gridFieldsR))
                    <div class="row">
                    @foreach($gridFieldsR as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @endif

                @if(isset($gridFooters))
                    @if(isset($gridFieldsL) || isset($gridFieldsR))
                    <hr />
                    @endif
                    <div class="row">
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

@if($header)
<hr style="margin-top: 0px;">
@endif
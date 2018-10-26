
@if($header)
<div class="row">
    <div class="{{$viewClass['label']}}"><h4 class="pull-right">{{ $label }}</h4></div>
    <div class="{{$viewClass['field']}}"></div>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">

    <div class="embed-{{$column}}-forms">

        <div class="embed-{{$column}}-form fields-group">
            @if(isset($gridFields) > 0)
                <div class="row">
                @foreach($gridFields as $gridField)
                    <div class="col-xs-12 col-md-{{12 / count($gridFields)}}">
                    @foreach($gridField as $field)
                        {!! $field->render() !!}
                    @endforeach
                    </div>
                @endforeach
                </div>
            @else
                @foreach($form->fields() as $field)
                    {!! $field->render() !!}
                @endforeach
            @endif
        </div>
    </div>
</div>

<hr style="margin-top: 0px;">
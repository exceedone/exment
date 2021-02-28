<div class="row">
    <div class="col-sm-12">
        <h4 class="field-header">{{$title}}</h4>
    </div>
</div>
<hr style="margin-top: 0px;">

@foreach($children as $child)
{!! $child->render() !!}

@if(!$loop->last)
<hr>
@endif
@endforeach

<div class="system-values">
    <div class="system-values-flows">
    @foreach($workflows as $item)
    <div class="system-values-flow d-inline-block">
        <p class="system-values-label d-inline-block"><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;{{$item['label']}}</p>
        <p class="d-inline-block">{!! $item['value'] !!}</p>
    </div>
    @endforeach
    </div>

    <ul class="system-values-list">
    @foreach($bodies as $item)
    <li>
        <p class="system-values-label">{{$item['label']}}</p>
        <p>{!! $item['value'] !!}</p>
    </li>
    @endforeach
    </ul>
</div>
<hr />
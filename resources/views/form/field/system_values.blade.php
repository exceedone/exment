@if ($positon == 'bottom')
<hr />
@endif
<div class="system-values">
    <div class="system-values-flows">
    @foreach($workflows as $item)
    <div class="system-values-flow">
        <p class="system-values-label"><i class="fa fa-check-square-o" aria-hidden="true"></i>&nbsp;{{$item['label']}}</p>
        <p class="system-values-item">{!! $item['value'] !!}</p>
    </div>
    @endforeach
    </div>

    <ul class="system-values-list">
    @foreach($bodies as $item)
    <li>
        <p class="system-values-label">{{$item['label']}}</p>
        <p class="system-values-item">{!! $item['value'] !!}</p>
    </li>
    @endforeach
    </ul>
</div>
@if ($positon == 'top')
<hr />
@endif
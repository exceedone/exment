<ul class="system-values">
@foreach($items as $item)
<li>
<h6>{{$item['label']}}</h6>
<p>{!! $item['value'] !!}</p>
</li>
@endforeach
</ul>
<hr />
<div class="btn-group pull-right" style="margin-right: 5px">
    <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
        <i class="fa fa-plus"></i>&nbsp; <span class="hidden-xs">{{ $label ?? null }}</span>
        <span class="caret"></span>
    </button>
    <ul class="dropdown-menu" role="menu">
        @foreach($menu as $m)
            <li><a href="{{ array_get($m, 'href') }}">{{ array_get($m, 'label') }}</a></li>
        @endforeach
    </ul>
</div>
<button type="button" data-value="{{$value}}" data-label="{{$valueLabel}}" class="btn btn-default btn-xs button-append-selectitem rowclick" data-target-selectitem="{{$target_selectitem}}">
    <i class="fa fa-arrow-down"></i><span class="hidden-xs">&nbsp;{{ $label }}</span>
</button>
&nbsp;

<a href="{{$model->getUrl()}}" target="_blank" rel="noopener" class="btn btn-default btn-xs" data-toggle="tooltip" data-placement="left" title="{{ exmtrans('common.open_blank') }}">
    <i class="fa fa-external-link"></i><span class="hidden-xs">&nbsp;{{ exmtrans('common.open') }}</span>
</a>
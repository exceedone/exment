<div {!! $modalAttributes !!}>
    <div {!! $modalInnerAttributes !!}>
      <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">{{ $header }}</h4>
        </div>
        <div class="modal-body">
            {!! $body !!}
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default pull-left" data-dismiss="modal">{{trans('admin.close')}}</button>
            @if($submit)
            <button type="button" class="btn btn-info modal-submit">{{ trans('admin.submit') }}</button>
            @endif
        </div>
  </div>
    </div>
</div>

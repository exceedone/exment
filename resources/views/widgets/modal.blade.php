<div {!! $modalAttributes !!} data-contentname="">
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
            <button type="button" class="btn btn-default pull-left modal-close" data-dismiss="modal">{{trans('admin.close')}}</button>
            <button type="button" class="btn btn-default pull-left modal-reset" data-dismiss="modal">{{trans('admin.reset')}}</button>

            <button type="button" class="btn btn-info modal-submit {!! $modalSubmitAttributes !!}">{{ trans('admin.submit') }}</button>

            <input type="hidden" class="modal-close-defaultlabel" value="{{ trans('admin.close') }}" />
            <input type="hidden" class="modal-submit-defaultlabel" value="{{ trans('admin.submit') }}" />
        </div>
    </div>
    </div>
</div>

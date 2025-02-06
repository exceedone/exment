<div {!! $modalAttributes !!} data-contentname="">
    <div {!! $modalInnerAttributes !!}>
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="myModalLabel">{{ $header }}</h4>
                <button type="button" class="close border-0 bg-transparent" data-bs-dismiss="modal"
                    aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                {!! $body !!}
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div>
                    <button type="button" class="btn btn-default modal-close"
                        data-bs-dismiss="modal">{{trans('admin.close')}}</button>
                    <button type="button" class="btn btn-default modal-reset"
                        data-bs-dismiss="modal">{{trans('admin.reset')}}</button>
                </div>

                <button type="button" class="btn btn-info modal-submit {!! $modalSubmitAttributes !!}">
                    {{ trans('admin.submit') }}
                </button>

                <input type="hidden" class="modal-close-defaultlabel" value="{{ trans('admin.close') }}" />
                <input type="hidden" class="modal-submit-defaultlabel" value="{{ trans('admin.submit') }}" />
            </div>

        </div>
    </div>
</div>
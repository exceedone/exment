<div class="modal fade bs-example-modal-lg" id="data_import_modal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" data-backdrop="static">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">{{ exmtrans('common.import') }}</h4>
        </div>
        <div class="modal-body">
            {!! $form !!}
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">{{trans('admin.close')}}</button>
        </div>
  </div>
    </div>
</div>

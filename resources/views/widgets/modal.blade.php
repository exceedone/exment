<div {!! $attributes !!} id="my-modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="modal-label">{{$label}}}</h4>
            </div>
            <div class="modal-body" id="modal-body">
                    {{$body}}}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">閉じる</button>
            </div>
        </div>
    </div>
</div>
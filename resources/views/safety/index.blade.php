<div class="box card p-3">
    <div class="box-header with-border d-flex align-items-center border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0"><i class="fa fa-paper-plane"></i> {{ exmtrans('safety.new_event_title') }}</h3>
    </div>
    <div class="box-body">
        <form method="POST" action="{{ admin_url('safety_check/send') }}" class="d-flex flex-wrap align-items-end gap-3"
              onsubmit="this.querySelector('button[type=submit]').disabled = true;">
            {{ csrf_field() }}
            <div>
                <label class="form-label" for="safety-title">{{ exmtrans('safety.col_title') }}</label>
                <input type="text" id="safety-title" name="title" class="form-control" required>
            </div>
            <div>
                <label class="form-label" for="safety-trigger-type">{{ exmtrans('safety.col_trigger_type') }}</label>
                <select id="safety-trigger-type" name="trigger_type" class="form-select">
                    <option value="manual">{{ exmtrans('safety.trigger_type_manual') }}</option>
                    <option value="drill">{{ exmtrans('safety.trigger_type_drill') }}</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">{{ exmtrans('safety.button_send') }}</button>
            </div>
        </form>
    </div>
</div>

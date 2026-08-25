<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-paper-plane"></i> {{ exmtrans('safety.new_event_title') }}</h3>
    </div>
    <div class="box-body">
        <form method="POST" action="{{ admin_url('safety_check/send') }}" class="form-inline">
            {{ csrf_field() }}
            <div class="form-group" style="margin-right:10px;">
                <label style="margin-right:5px;">{{ exmtrans('safety.col_title') }}</label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="form-group" style="margin-right:10px;">
                <label style="margin-right:5px;">{{ exmtrans('safety.col_trigger_type') }}</label>
                <select name="trigger_type" class="form-control">
                    <option value="manual">{{ exmtrans('safety.trigger_type_manual') }}</option>
                    <option value="drill">{{ exmtrans('safety.trigger_type_drill') }}</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">{{ exmtrans('safety.button_send') }}</button>
        </form>
    </div>
</div>

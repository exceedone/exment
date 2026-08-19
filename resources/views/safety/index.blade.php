<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('safety.new_event_title') }}</h3>
    </div>
    <div class="box-body">
        <form method="POST" action="{{ admin_url('safety_check/send') }}" class="form-inline">
            {{ csrf_field() }}
            <div class="form-group">
                <label>{{ exmtrans('safety.col_title') }}</label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>{{ exmtrans('safety.col_trigger_type') }}</label>
                <select name="trigger_type" class="form-control">
                    <option value="manual">{{ exmtrans('safety.trigger_type_manual') }}</option>
                    <option value="drill">{{ exmtrans('safety.trigger_type_drill') }}</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">{{ exmtrans('safety.button_send') }}</button>
        </form>
    </div>
</div>

<div class="box">
    <div class="box-body">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('safety.col_title') }}</th>
                    <th>{{ exmtrans('safety.col_trigger_type') }}</th>
                    <th>{{ exmtrans('safety.col_event_status') }}</th>
                    <th>{{ exmtrans('safety.col_triggered_at') }}</th>
                    <th>{{ exmtrans('safety.col_sent_count') }}</th>
                    <th>{{ exmtrans('safety.col_target_count') }}</th>
                    <th>{{ exmtrans('safety.col_answered_count') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($events as $event)
                    <tr>
                        <td>{{ $event->getValue('title') }}</td>
                        <td>{{ $event->getValue('trigger_type') }}</td>
                        <td>{{ $event->getValue('event_status') }}</td>
                        <td>{{ $event->getValue('triggered_at') }}</td>
                        <td>{{ $event->getValue('sent_count') }}</td>
                        <td>{{ $event->getValue('target_count') }}</td>
                        <td>{{ $answeredCounts[$event->id] ?? 0 }}</td>
                        <td>
                            <form method="POST" action="{{ admin_url('safety_check/' . $event->id . '/resend') }}" style="display:inline-block;">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-default">{{ exmtrans('safety.button_resend') }}</button>
                            </form>
                            <form method="POST" action="{{ admin_url('safety_check/' . $event->id . '/close') }}" style="display:inline-block;">
                                {{ csrf_field() }}
                                <button type="submit" class="btn btn-sm btn-default">{{ exmtrans('safety.button_close') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">{{ exmtrans('common.message.notfound') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

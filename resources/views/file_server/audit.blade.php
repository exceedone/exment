{{-- File server, phase 5 (ISS00379): the audit log of a workspace. Data from FileServerGovernanceController::audit. --}}
@include('exment::file_server._assets')
<div class="exm-fs" id="exm-fs-audit">
    <div class="exm-fs-toolbar">
        <a class="btn btn-sm btn-default" href="{{ $back_url }}"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ $workspace->name }}</a>
        <span class="exm-fs-spacer"></span>
        <a class="btn btn-sm btn-default" href="{{ $csv_url }}"><i class="fa fa-file-text-o" aria-hidden="true"></i> {{ exmtrans('file_server.audit_csv') }}</a>
    </div>

    <form class="exm-fs-filters" method="get" action="{{ $action }}">
        <label class="exm-fs-filter"><span>{{ exmtrans('file_server.audit_action') }}</span>
            <select name="action" class="form-control input-sm">
                <option value=""></option>
                @foreach ($actions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="exm-fs-filter"><span>{{ exmtrans('file_server.audit_node') }}</span>
            <input type="text" name="node" class="form-control input-sm" value="{{ $filters['node'] }}" maxlength="100">
        </label>
        <label class="exm-fs-filter"><span>{{ exmtrans('file_server.audit_from') }}</span>
            <input type="date" name="from" class="form-control input-sm" value="{{ $filters['from'] }}">
        </label>
        <label class="exm-fs-filter"><span>{{ exmtrans('file_server.audit_to') }}</span>
            <input type="date" name="to" class="form-control input-sm" value="{{ $filters['to'] }}">
        </label>
        <label class="exm-fs-filter" style="font-weight:normal"><input type="checkbox" name="user_id" value="link" @checked($filters['user_id'] === 'link')> {{ exmtrans('file_server.audit_only_links') }}</label>
        <button type="submit" class="btn btn-sm btn-default"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('file_server.search') }}</button>
    </form>

    <p class="exm-fs-muted">{{ exmtrans('file_server.audit_total', ['count' => $total]) }}</p>
    <div class="table-responsive">
        <table class="table table-condensed exm-fs-table">
            <thead><tr>
                <th>{{ exmtrans('file_server.audit_at') }}</th><th>{{ exmtrans('file_server.audit_action') }}</th><th>{{ exmtrans('file_server.audit_user') }}</th>
                <th>{{ exmtrans('file_server.audit_node') }}</th><th>{{ exmtrans('file_server.audit_detail') }}</th><th>IP</th>
            </tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="exm-fs-muted" style="white-space:nowrap">{{ optional($row['created_at'])->format('Y/m/d H:i:s') }}</td>
                        <td>{{ $row['action_label'] }}</td>
                        <td>{{ $row['user'] }}</td>
                        <td style="overflow-wrap:anywhere">
                            @if ($row['node_url'])<a href="{{ $row['node_url'] }}">{{ $row['node_name'] }}</a>@else{{ $row['node_name'] }}@endif
                        </td>
                        <td class="exm-fs-muted" style="overflow-wrap:anywhere;font-size:.85em">{{ $row['detail'] }}</td>
                        <td class="exm-fs-muted">{{ $row['ip'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="exm-fs-muted">{{ exmtrans('file_server.audit_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>
        @if ($prev_url)<a class="btn btn-sm btn-default" href="{{ $prev_url }}">&laquo;</a>@endif
        @if ($next_url)<a class="btn btn-sm btn-default" href="{{ $next_url }}">&raquo;</a>@endif
    </div>
</div>

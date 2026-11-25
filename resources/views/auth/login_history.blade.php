<div id="login-history">
    <p class="text-muted small">{{ exmtrans('login.history_help', ['count' => $rows]) }}</p>
    <div class="table-responsive">
    <table class="table table-condensed table-striped">
        <thead>
            <tr>
                <th>{{ exmtrans('log_management.column.datetime') }}</th>
                <th>{{ exmtrans('log_management.column.result') }}</th>
                <th>IP</th>
                <th>{{ exmtrans('log_management.column.user_agent') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse($logins as $login)
            <tr>
                <td style="white-space:nowrap">{{ optional($login->created_at)->format('Y/m/d H:i:s') }}</td>
                <td>
                    <span class="label {{ $login->result === 'success' ? 'label-success' : ($login->result === 'logout' ? 'label-default' : 'label-danger') }}">{{ exmtrans('log_management.result.' . $login->result) }}</span>
                    @if($login->failure_reason) <span class="small">{{ exmtrans('log_management.reason.' . $login->failure_reason) }}</span> @endif
                </td>
                <td>{{ $login->ip }}</td>
                <td class="small" title="{{ $login->user_agent }}">{{ \Exceedone\Exment\Services\Auth2factor\LoginContext::browser(strval($login->user_agent)) ?? \Illuminate\Support\Str::limit(strval($login->user_agent), 40) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-muted">{{ exmtrans('log_management.no_logins') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

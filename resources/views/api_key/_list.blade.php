{{-- The keys (UserApiKeyController::rows). $revoke_url: a closure id => url; $admin: with the users. --}}
<div class="table-responsive">
<table class="table table-condensed exm-api-key-list">
    <thead><tr>
        @if ($admin)<th>{{ exmtrans('api_key.user') }}</th>@endif
        <th>{{ exmtrans('api_key.name') }}</th>
        <th>{{ exmtrans('api_key.key') }}</th>
        <th>{{ exmtrans('api_key.scopes') }}</th>
        <th>{{ exmtrans('api_key.limits') }}</th>
        <th>{{ exmtrans('api_key.expires_at') }}</th>
        <th>{{ exmtrans('api_key.last_used') }}</th>
        <th>{{ exmtrans('api_key.status') }}</th>
        <th></th>
    </tr></thead>
    <tbody>
    @forelse ($keys as $k)
        <tr class="{{ $k['status'] === 'active' ? '' : 'text-muted' }}">
            @if ($admin)
                <td>{{ $k['user_name'] }}
                    @if (!$read_only)
                    <form method="post" action="{{ $revoke_user_url($k['user_id']) }}" style="display:inline" onsubmit="return confirm('{{ exmtrans('api_key.confirm_revoke_user') }}');">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-xs btn-link">{{ exmtrans('api_key.revoke_user') }}</button>
                    </form>
                    @endif
                </td>
            @endif
            <td>{{ $k['name'] }}<br><small>{{ $k['created_at'] }}</small></td>
            <td><code>{{ $k['prefix'] }}…</code></td>
            <td><small>{{ $k['scopes'] }}</small></td>
            <td><small>
                {{ exmtrans('api_key.tables') }}: {{ $k['tables'] ?? exmtrans('api_key.all_tables') }}<br>
                {{ exmtrans('api_key.allowed_ips') }}: {{ $k['ips'] !== '' ? $k['ips'] : exmtrans('api_key.any_ip') }}<br>
                {{ exmtrans('api_key.client_cert') }}: {{ $k['cert'] ? exmtrans('api_key.needed') : '-' }}
            </small></td>
            <td>{{ $k['expires_at'] }}</td>
            <td><small>{{ $k['last_used_at'] ?? '-' }}<br>{{ $k['last_used_ip'] }} ({{ $k['use_count'] }})
                @if (isset($k['last_refused_at']))
                    <br><span class="text-danger">{{ exmtrans('api_key.last_refused') }}: {{ $k['last_refused_at'] }} {{ $k['last_refused_reason'] }}</span>
                @endif
            </small></td>
            <td>{{ exmtrans('api_key.statuses.' . $k['status']) }}</td>
            <td>
                @if ($k['status'] === 'active' && !$read_only)
                <form method="post" action="{{ $revoke_url($k['id']) }}" onsubmit="return confirm('{{ exmtrans('api_key.confirm_revoke') }}');">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-xs btn-danger">{{ exmtrans('api_key.revoke') }}</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ $admin ? 9 : 8 }}">{{ exmtrans('api_key.no_keys') }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>

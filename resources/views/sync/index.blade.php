{{-- The data sync (ISS00113): SyncController@index --}}
@if($appKeyWarning)
<div class="alert alert-warning">{{ exmtrans('sync.app_key_warning') }}</div>
@endif
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('sync.connections') }}</h3>
        <div class="box-tools">
            @foreach($connectors as $key => $label)
                <a class="btn btn-sm btn-primary" href="{{ admin_url('sync/connections/create?connector=' . urlencode($key)) }}"><i class="fa fa-plus"></i> {{ $label }}</a>
            @endforeach
        </div>
    </div>
    <div class="box-body">
        @if(count($connectors) === 0)
            <p class="text-muted">{{ exmtrans('sync.no_connector') }}</p>
        @endif
        @forelse($connections as $connection)
            <div class="sync-connection" style="margin-bottom: 1.5em;">
                <h4>
                    {{ $connection->name }}
                    <small>{{ $connectors[$connection->connector] ?? $connection->connector }} ・ {{ $connection->enabled ? exmtrans('sync.enabled') : exmtrans('sync.disabled') }}</small>
                </h4>
                <a class="btn btn-xs btn-default" href="{{ admin_urls('sync', 'connections', $connection->id, 'edit') }}">{{ trans('admin.edit') }}</a>
                <form method="post" action="{{ admin_urls('sync', 'connections', $connection->id, 'test') }}" style="display:inline">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-xs btn-default">{{ exmtrans('sync.test') }}</button>
                </form>
                <a class="btn btn-xs btn-primary" href="{{ admin_url('sync/mappings/create?connection=' . $connection->id) }}"><i class="fa fa-plus"></i> {{ exmtrans('sync.add_mapping') }}</a>
                <table class="table table-condensed" style="margin-top: .5em;">
                    <thead><tr>
                        <th>{{ exmtrans('sync.mapping') }}</th>
                        <th>{{ exmtrans('sync.table') }}</th>
                        <th>{{ exmtrans('sync.interval') }}</th>
                        <th>{{ exmtrans('sync.last_run') }}</th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    @foreach($mappings->get($connection->id, collect()) as $mapping)
                        @php $last = $lastRuns->get($mapping->id); @endphp
                        <tr>
                            <td>{{ $mapping->name }}<br><small class="text-muted">{{ $mapping->remote_label ?: $mapping->remote_object }}</small></td>
                            <td>{{ $tables[$mapping->custom_table_id] ?? $mapping->custom_table_id }}</td>
                            <td>{{ $mapping->enabled ? exmtrans('sync.every_minutes', ['minutes' => $mapping->interval_minutes]) : exmtrans('sync.disabled') }}</td>
                            <td>
                                @if(isset($last))
                                    <a href="{{ admin_urls('sync', 'runs', $last->id) }}">{{ exmtrans('sync.status.' . $last->status) }}</a>
                                    <small class="text-muted">{{ optional($last->started_at)->format('Y-m-d H:i') }}@if($last->dry_run) ({{ exmtrans('sync.dry_run') }})@endif</small>
                                @endif
                            </td>
                            <td style="white-space: nowrap;">
                                <a class="btn btn-xs btn-default" href="{{ admin_urls('sync', 'mappings', $mapping->id, 'edit') }}">{{ trans('admin.edit') }}</a>
                                <form method="post" action="{{ admin_urls('sync', 'mappings', $mapping->id, 'run') }}" style="display:inline">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                    <input type="hidden" name="dry" value="1">
                                    <button type="submit" class="btn btn-xs btn-default">{{ exmtrans('sync.dry_run') }}</button>
                                </form>
                                <form method="post" action="{{ admin_urls('sync', 'mappings', $mapping->id, 'run') }}" style="display:inline">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                    <button type="submit" class="btn btn-xs btn-warning">{{ exmtrans('sync.run_now') }}</button>
                                </form>
                                <a class="btn btn-xs btn-default" href="{{ admin_urls('sync', 'mappings', $mapping->id, 'links') }}">{{ exmtrans('sync.links_csv') }}</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-muted">{{ exmtrans('sync.no_connection') }}</p>
        @endforelse
    </div>
</div>

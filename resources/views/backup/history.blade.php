{{-- The history of the runs of the backup and the restore (ISS02943): BackupController@index --}}
@php
    $statusLabel = ['queued' => 'label-default', 'running' => 'label-info', 'succeeded' => 'label-success', 'warning' => 'label-warning', 'failed' => 'label-danger', 'stalled' => 'label-danger'];
@endphp
<div class="box box-default backup-history" id="backup-history">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('backup.run.screen.history_header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('backup.run.screen.history_help', ['count' => $keepRuns]) }}</p>
        <p class="backup-space">
            {{ exmtrans('backup.run.screen.used_space', ['count' => number_format($space['count']), 'size' => bytesToHuman($space['bytes']) ?? '0 B']) }}
            @if(isset($space['free']))
                &nbsp;/&nbsp;{{ exmtrans('backup.run.screen.free_space', ['size' => bytesToHuman($space['free'])]) }}
            @endif
        </p>
        @if($space['low'])
        <div class="alert alert-warning backup-space-low">{{ exmtrans('backup.run.screen.free_space_low', ['size' => bytesToHuman($space['free'])]) }}</div>
        @endif
        @if(count($runs) === 0)
            <p class="backup-history-empty">{{ exmtrans('backup.run.screen.no_runs') }}</p>
        @else
        <div class="table-responsive">
        <table class="table table-hover backup-history-table">
            <thead>
                <tr>
                    <th>{{ exmtrans('backup.run.screen.column.started_at') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.kind') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.status') }}</th>
                    <th class="d-none d-md-table-cell">{{ exmtrans('backup.run.screen.column.user') }}</th>
                    <th class="d-none d-md-table-cell">{{ exmtrans('backup.run.screen.column.file') }}</th>
                    <th class="d-none d-md-table-cell">{{ exmtrans('backup.run.screen.column.size') }}</th>
                    <th class="d-none d-md-table-cell">{{ exmtrans('backup.run.screen.column.duration') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.log') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($runs as $run)
                <tr data-run="{{ $run['uuid'] }}">
                    <td style="white-space: nowrap;">{{ $run['started_at'] ?? '-' }}</td>
                    <td>{{ $run['kind_label'] }}<br class="d-md-none"><small class="text-muted"> ({{ $run['trigger_label'] }})</small></td>
                    <td>
                        <span class="label {{ $statusLabel[$run['status']] ?? 'label-default' }}">{{ $run['status_label'] }}</span>
                        @if($run['warnings'] > 0 || $run['errors'] > 0)
                            <small class="text-muted" style="white-space: nowrap;">{{ exmtrans('backup.run.warning') }} {{ $run['warnings'] }} / {{ exmtrans('backup.run.error') }} {{ $run['errors'] }}</small>
                        @endif
                    </td>
                    <td class="d-none d-md-table-cell">{{ $run['user_name'] ?? exmtrans('backup.run.screen.system_user') }}</td>
                    <td class="d-none d-md-table-cell" style="word-break: break-all;">{{ $run['file_name'] ?? '-' }}</td>
                    <td class="d-none d-md-table-cell" style="white-space: nowrap;">{{ $run['file_size_text'] ?? '-' }}</td>
                    <td class="d-none d-md-table-cell" style="white-space: nowrap;">{{ $run['duration_text'] ?? '-' }}</td>
                    <td style="white-space: nowrap;">
                        <a href="{{ admin_urls('backup', 'runs', $run['uuid']) }}" data-bs-toggle="tooltip" title="{{ exmtrans('backup.run.screen.view_log') }}" aria-label="{{ exmtrans('backup.run.screen.view_log') }}"><i class="fa fa-file-text-o" aria-hidden="true"></i></a>
                        @if($run['has_log'])
                        &nbsp;<a href="{{ admin_urls('backup', 'runs', $run['uuid'], 'log') }}" target="_blank" rel="noopener" data-bs-toggle="tooltip" title="{{ exmtrans('backup.run.screen.download_log') }}" aria-label="{{ exmtrans('backup.run.screen.download_log') }}"><i class="fa fa-download" aria-hidden="true"></i></a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</div>

{{-- A run of the backup or the restore: its stages and its log (ISS02943): BackupController@runShow --}}
@php
    $statusLabel = ['queued' => 'label-default', 'running' => 'label-info', 'succeeded' => 'label-success', 'warning' => 'label-warning', 'failed' => 'label-danger', 'stalled' => 'label-danger'];
    $levelClass = ['[WARNING]' => 'text-warning', '[ERROR]' => 'text-danger'];
@endphp
<div class="box box-default backup-run" id="backup-run" data-run="{{ $run['uuid'] }}">
    <div class="box-header with-border">
        <h3 class="box-title">{{ $run['kind_label'] }} <span class="label {{ $statusLabel[$run['status']] ?? 'label-default' }}">{{ $run['status_label'] }}</span></h3>
        <div class="float-end">
            <a href="{{ $backUrl }}" class="btn btn-sm btn-default"><i class="fa fa-list" aria-hidden="true"></i><span class="d-none d-md-inline"> {{ exmtrans('backup.run.screen.back') }}</span></a>
            @if($hasLog)
            <a href="{{ $downloadUrl }}" class="btn btn-sm btn-twitter" target="_blank" rel="noopener"><i class="fa fa-download" aria-hidden="true"></i><span class="d-none d-md-inline"> {{ exmtrans('backup.run.screen.download_log') }}</span></a>
            @endif
        </div>
    </div>
    <div class="box-body">
        <dl class="row" style="margin-bottom: 0;">
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.screen.column.started_at') }}</dt><dd class="col-7 col-md-4">{{ $run['started_at'] ?? '-' }}</dd>
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.screen.finished_at') }}</dt><dd class="col-7 col-md-4">{{ $run['finished_at'] ?? '-' }}</dd>
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.screen.column.user') }}</dt><dd class="col-7 col-md-4">{{ $user_name ?? exmtrans('backup.run.screen.system_user') }} ({{ $trigger }})</dd>
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.screen.column.file') }}</dt><dd class="col-7 col-md-4" style="word-break: break-all;">{{ $run['file_name'] ?? '-' }}@if($file_size_text) ({{ $file_size_text }})@endif</dd>
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.screen.column.duration') }}</dt><dd class="col-7 col-md-4">{{ isset($run['elapsed_seconds']) ? \Exceedone\Exment\Services\BackupRestore\RunProgress::duration(intval($run['elapsed_seconds'])) : '-' }}</dd>
            <dt class="col-5 col-md-2">{{ exmtrans('backup.run.warning') }} / {{ exmtrans('backup.run.error') }}</dt><dd class="col-7 col-md-4">{{ $run['warnings'] }} / {{ $run['errors'] }}</dd>
        </dl>
        @if(!empty($run['message']))
        <div class="alert {{ in_array($run['status'], ['failed', 'stalled']) ? 'alert-danger' : 'alert-info' }}" style="margin-top: 10px; white-space: pre-wrap;">{{ $run['message'] }}</div>
        @endif

        <h4 style="margin-top: 16px;">{{ exmtrans('backup.run.screen.stages_header') }}</h4>
        <div class="table-responsive">
        <table class="table table-condensed backup-run-stages">
            <thead>
                <tr>
                    <th>{{ exmtrans('backup.run.screen.column.stage') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.started_at') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.seconds') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.count') }}</th>
                    <th>{{ exmtrans('backup.run.screen.column.size') }}</th>
                    <th>{{ exmtrans('backup.run.warning') }} / {{ exmtrans('backup.run.error') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($run['stages'] as $stage)
                <tr class="{{ $stage['state'] === 'stopped' ? 'danger' : '' }}">
                    <td>
                        @if($stage['state'] === 'done')<i class="fa fa-check text-green" aria-hidden="true"></i>@elseif($stage['state'] === 'current')<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>@elseif($stage['state'] === 'stopped')<i class="fa fa-times text-red" aria-hidden="true"></i>@else<i class="fa fa-circle-o text-muted" aria-hidden="true"></i>@endif
                        {{ $stage['label'] }}
                    </td>
                    <td style="white-space: nowrap;">{{ $stage['started_at'] ?? '-' }}</td>
                    <td>{{ isset($stage['seconds']) ? \Exceedone\Exment\Services\BackupRestore\RunProgress::duration(intval($stage['seconds'])) : '-' }}</td>
                    <td>{{ isset($stage['count']) ? number_format(intval($stage['count'])) : '-' }}</td>
                    <td style="white-space: nowrap;">{{ !empty($stage['bytes']) ? bytesToHuman($stage['bytes']) : '-' }}</td>
                    <td>{{ intval($stage['warnings']) }} / {{ intval($stage['errors']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <h4 style="margin-top: 16px;">{{ exmtrans('backup.run.screen.log_header') }}</h4>
        @if(!$hasLog)
            <p class="text-muted backup-run-nolog">{{ exmtrans('backup.run.screen.log_none') }}</p>
        @else
            @if($cut)
            <p class="text-muted">{{ exmtrans('backup.run.screen.log_cut', ['count' => $maxLines]) }}</p>
            @endif
            <pre class="backup-run-log" style="max-height: 60vh; overflow: auto; white-space: pre-wrap; word-break: break-all; font-size: 12px;">@foreach($lines as $line)<span class="{{ collect($levelClass)->first(fn ($class, $level) => str_contains($line, $level)) ?? '' }}">{{ $line }}</span>
@endforeach</pre>
        @endif
    </div>
</div>
@if($run['active'])
{{-- the run goes on: its progress, polled (a restore that ends signs out) --}}
@include('exment::backup.progress', ['active' => $run, 'statusUrl' => $statusUrl, 'pollMs' => $pollMs, 'logoutUrl' => $logoutUrl])
@endif

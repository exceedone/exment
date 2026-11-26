{{-- What a restore of a stored backup replaces (ISS02943): BackupController@importModal, Restore::preview --}}
@if(!isset($preview))
    <span class="text-muted">{{ exmtrans('backup.run.screen.preview_none') }}</span>
@else
    <ul class="backup-restore-preview" style="padding-left: 1.2em; margin: 0;">
        <li>
            @if($preview['database'])
                {{ exmtrans('backup.run.screen.preview_database', ['count' => $preview['tables']]) }}
            @else
                {{ exmtrans('backup.run.screen.preview_no_database') }}
            @endif
        </li>
        @foreach($preview['folders'] as $key => $folder)
        <li>{{ exmtrans('backup.run.screen.preview_folder', ['name' => \Illuminate\Support\Facades\Lang::has('exment::exment.backup.backup_target_options.' . $key) ? exmtrans('backup.backup_target_options.' . $key) : $key, 'files' => number_format($folder['files']), 'size' => bytesToHuman($folder['bytes'])]) }}</li>
        @endforeach
        @if($preview['env'])
        <li>{{ exmtrans('backup.run.screen.preview_env') }}</li>
        @endif
        <li>{{ exmtrans('backup.run.screen.preview_versions', ['exment' => $preview['exment'] ?? '-', 'laravel' => $preview['laravel'] ?? '-', 'current_exment' => $preview['current_exment'] ?? '-', 'current_laravel' => $preview['current_laravel'] ?? '-']) }}</li>
    </ul>
@endif

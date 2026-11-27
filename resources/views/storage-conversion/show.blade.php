@php
    $storage = $status['storage'];
    $state = $storage['state'] ?? null;
    $disabled = !$execution['available'] ? 'disabled' : '';
    // a text of the language for a code of the service; the code or the service's own text when there is none
    $sc = function (string $group, $code, $fallback = null) {
        $key = 'storage_conversion.' . $group . '.' . $code;
        return trans()->has('exment::exment.' . $key) ? exmtrans($key) : ($fallback ?? $code);
    };
    $modeText = function ($mode) {
        return $mode === 'physical' ? exmtrans('custom_table.storage_mode_physical') : ($mode === 'json' ? exmtrans('custom_table.storage_mode_json') : $mode);
    };
@endphp

@include('exment::storage-conversion.execution', ['execution' => $execution])

<p><a href="{{ admin_url('storage_conversion') }}">&laquo; {{ exmtrans('storage_conversion.back') }}</a></p>

{{-- Status --}}
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ $table->table_view_name }} <small class="text-muted">({{ $table->table_name }})</small></h3>
    </div>
    <div class="box-body">
        @if($storage)
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ exmtrans('storage_conversion.col_mode') }}</dt><dd class="col-sm-9">{{ $modeText($storage['mode']) }}</dd>
                <dt class="col-sm-3">{{ exmtrans('storage_conversion.col_state') }}</dt><dd class="col-sm-9">{{ exmtrans('storage_conversion.state_' . $state) }}</dd>
                <dt class="col-sm-3">{{ exmtrans('storage_conversion.col_generation') }}</dt><dd class="col-sm-9">{{ $storage['generation'] }}</dd>
            </dl>
        @else
            <p class="text-muted mb-0">{{ exmtrans('storage_conversion.not_installed') }}</p>
        @endif
    </div>
</div>

{{-- Preflight (read-only; actions re-check their own preconditions) --}}
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.preflight') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('storage_conversion.preflight_help') }}</p>
        <p>{{ $status['preflight']['eligible'] ? exmtrans('storage_conversion.preflight_ok') : exmtrans('storage_conversion.preflight_blocked') }}</p>
        @foreach(['blockers', 'warnings'] as $kind)
            @if(!empty($status['preflight'][$kind]))
                <h4 class="fs-6">{{ exmtrans('storage_conversion.' . $kind) }}</h4>
                <ul>
                    @foreach($status['preflight'][$kind] as $item)
                        <li><code>{{ $item['code'] ?? '' }}</code>@if(!empty($item['column'])) ({{ $item['column'] }})@endif {{ $sc('blocker_text', $item['code'] ?? '', $item['message'] ?? '') }}@if(trans()->has('exment::exment.storage_conversion.blocker_text.' . ($item['code'] ?? '')) && !empty($item['message'])) <small class="text-muted">({{ $item['message'] }})</small>@endif</li>
                    @endforeach
                </ul>
            @endif
        @endforeach
        <h4 class="fs-6">{{ exmtrans('storage_conversion.dependencies') }}</h4>
        <ul>
            @foreach($status['preflight']['dependencies'] as $code => $text)
                <li><code>{{ $code }}</code> {{ $sc('dependency_text', $code, $text) }}</li>
            @endforeach
        </ul>
    </div>
</div>

{{-- Progress, findings, recoverable failures --}}
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.progress') }}</h3>
    </div>
    <div class="box-body table-responsive">
        @if(empty($status['runs']))
            <p class="text-muted mb-0">{{ exmtrans('storage_conversion.no_runs') }}</p>
        @else
        <table class="table">
            <thead><tr>
                <th>{{ exmtrans('storage_conversion.col_run') }}</th><th>{{ exmtrans('storage_conversion.col_status') }}</th>
                <th>{{ exmtrans('storage_conversion.col_checkpoint') }}</th><th>{{ exmtrans('storage_conversion.col_pending') }}</th>
                <th>{{ exmtrans('storage_conversion.col_counts') }}</th><th>{{ exmtrans('storage_conversion.col_error') }}</th>
            </tr></thead>
            <tbody>
            @foreach($status['runs'] as $kind => $run)
                <tr>
                    <td>{{ exmtrans('storage_conversion.run_' . $kind) }} #{{ $run['id'] }}</td>
                    <td>{{ $sc('status_text', $run['status']) }}@if($run['result']) / {{ $sc('status_text', $run['result']) }}@endif</td>
                    <td>{{ $run['checkpoint_id'] }} / {{ $run['boundary_id'] }}</td>
                    <td>{{ $run['pending'] }}</td>
                    <td><small>
                        @foreach(['rows', 'written', 'unchanged', 'matched', 'deleted', 'pending_added', 'pending_resolved', 'batches'] as $c)
                            {{ $c }}={{ $run['counts'][$c] ?? 0 }}
                        @endforeach
                    </small></td>
                    <td>@if($run['error'])<span class="text-danger">{{ $run['error'] }}</span> <small>{{ exmtrans('storage_conversion.error_recoverable') }}</small>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

{{-- L5 findings of the completed verification (counts only) --}}
@if($status['l5_report'])
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.l5_report') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('storage_conversion.l5_help') }}</p>
        @if(empty($status['l5_report']['l5']))
            <p>{{ exmtrans('storage_conversion.l5_none') }}</p>
        @else
        <table class="table table-sm">
            <thead><tr><th>{{ exmtrans('storage_conversion.col_column') }}</th><th>{{ exmtrans('storage_conversion.col_reason') }}</th><th>{{ exmtrans('storage_conversion.col_count') }}</th></tr></thead>
            <tbody>
            @foreach($status['l5_report']['l5'] as $column => $reasons)
                @foreach($reasons as $reason => $count)
                    <tr><td>{{ $column }}</td><td>{{ exmtrans('storage_conversion.reason_' . $reason) }}</td><td>{{ $count }}</td></tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
        @endif
        <p><small>{{ exmtrans('storage_conversion.report_version') }}: <code>{{ $status['l5_report']['version'] }}</code></small></p>
    </div>
</div>
@endif

{{-- Actions: offered by state; every one is re-checked by its service and refused while unavailable --}}
<style>
    .sc-actions form { background: #fff; border: 1px solid #e3e8ef; border-left: 4px solid #2563eb; border-radius: 10px; padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(16, 24, 40, .05); transition: box-shadow .15s ease, transform .15s ease; }
    .sc-actions form:hover { box-shadow: 0 4px 12px rgba(16, 24, 40, .09); transform: translateY(-1px); }
    .sc-actions form.sc-action--neutral { border-left-color: #94a3b8; }
    .sc-actions form.sc-action--warn { border-left-color: #f59e0b; background: #fffbeb; }
    .sc-actions form.sc-action--danger { border-left-color: #dc2626; background: #fef2f2; }
    .sc-actions form .btn { border-radius: 8px; font-weight: 600; padding: 8px 18px; }
    .sc-actions form .btn:disabled, .sc-actions form .btn.disabled { opacity: .45; cursor: not-allowed; }
    .sc-actions form input[type=text] { font-family: Consolas, Menlo, monospace; border-radius: 8px; }
    .sc-actions form p.text-muted { margin-bottom: 10px; line-height: 1.5; }
    .sc-actions .sc-danger-zone { display: flex; align-items: center; gap: 8px; margin: 22px 0 10px; color: #b91c1c; font-weight: 700; font-size: .85rem; letter-spacing: .04em; text-transform: uppercase; }
    .sc-actions .sc-danger-zone::after { content: ""; flex: 1; border-top: 1px dashed #fca5a5; }
</style>
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.actions') }}</h3>
    </div>
    <div class="box-body sc-actions">
        @if(in_array($state, ['json', 'provisioned'], true))
            <form method="POST" action="{{ $actionBase }}/provision" class="mb-3 sc-action--neutral">{{ csrf_field() }}
                <button type="submit" class="btn btn-default" {{ $disabled }}><i class="fa fa-database"></i> {{ exmtrans('storage_conversion.action_provision') }}</button>
            </form>
        @endif
        @if($state === 'provisioned')
            <form method="POST" action="{{ $actionBase }}/dualwrite" class="mb-3">{{ csrf_field() }}
                <button type="submit" class="btn btn-primary" {{ $disabled }}><i class="fa fa-play"></i> {{ exmtrans('storage_conversion.action_dualwrite') }}</button>
            </form>
            <div class="sc-danger-zone"><i class="fa fa-exclamation-triangle"></i> {{ exmtrans('storage_conversion.danger_zone') }}</div>
            <form method="POST" action="{{ $actionBase }}/cleanup" class="mb-3 sc-action--warn sc-confirm-form">{{ csrf_field() }}
                <p class="text-muted">{{ exmtrans('storage_conversion.cleanup_help') }}</p>
                <input type="text" name="confirm_table_name" class="form-control mb-2" placeholder="{{ $table->table_name }}" data-confirm-value="{{ $table->table_name }}" autocomplete="off" required>
                <button type="submit" class="btn btn-warning" {{ $disabled }}><i class="fa fa-eraser"></i> {{ exmtrans('storage_conversion.action_cleanup') }}</button>
            </form>
            <form method="POST" action="{{ $actionBase }}/deprovision" class="mb-3 sc-action--danger sc-confirm-form">{{ csrf_field() }}
                <p class="text-muted">{{ exmtrans('storage_conversion.deprovision_help') }}</p>
                <input type="text" name="confirm_table_name" class="form-control mb-2" placeholder="{{ $table->table_name }}" data-confirm-value="{{ $table->table_name }}" autocomplete="off" required>
                <button type="submit" class="btn btn-danger" {{ $disabled }}><i class="fa fa-trash"></i> {{ exmtrans('storage_conversion.action_deprovision') }}</button>
            </form>
            <script>
                // The button stays locked until the exact table name is typed; the server still validates it.
                (function () {
                    function sync(form) {
                        var input = form.querySelector('input[data-confirm-value]');
                        var btn = form.querySelector('button[type=submit]');
                        if (!input || !btn || btn.hasAttribute('data-server-disabled')) { return; }
                        btn.disabled = input.value.trim() !== input.getAttribute('data-confirm-value');
                    }
                    document.querySelectorAll('.sc-confirm-form').forEach(function (form) {
                        var btn = form.querySelector('button[type=submit]');
                        if (btn && btn.disabled) { btn.setAttribute('data-server-disabled', '1'); }
                        form.addEventListener('input', function () { sync(form); });
                        sync(form);
                    });
                })();
            </script>
        @endif
        @if(in_array($state, ['dualwrite', 'backfilling'], true))
            <form method="POST" action="{{ $actionBase }}/backfill" class="mb-3">{{ csrf_field() }}
                @if($state === 'dualwrite')
                    @include('exment::storage-conversion.attestation', ['codes' => $attestationCodes])
                @endif
                <button type="submit" class="btn btn-primary" {{ $disabled }}>{{ exmtrans($state === 'dualwrite' ? 'storage_conversion.action_backfill' : 'storage_conversion.action_resume') }}</button>
            </form>
        @endif
        @if(in_array($state, ['backfilling', 'verifying', 'physical_read'], true))
            <form method="POST" action="{{ $actionBase }}/verify" class="mb-3">{{ csrf_field() }}
                <button type="submit" class="btn btn-default" {{ $disabled }}>{{ exmtrans('storage_conversion.action_verify') }}</button>
            </form>
        @endif
        @if($state === 'verifying')
            <form method="POST" action="{{ $actionBase }}/await_confirmation" class="mb-3">{{ csrf_field() }}
                <button type="submit" class="btn btn-primary" {{ $disabled }}>{{ exmtrans('storage_conversion.action_await_confirmation') }}</button>
            </form>
        @endif
        @if($state === 'awaiting_confirmation' && $status['l5_report'])
            <form method="POST" action="{{ $actionBase }}/cutover" class="mb-3 sc-action--danger">{{ csrf_field() }}
                <input type="hidden" name="run_id" value="{{ $status['l5_report']['run_id'] }}">
                <input type="hidden" name="generation" value="{{ $status['l5_report']['generation'] }}">
                <input type="hidden" name="report_version" value="{{ $status['l5_report']['version'] }}">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="confirm_l5" name="confirm_l5" value="1" required>
                    <label class="form-check-label" for="confirm_l5">{{ exmtrans('storage_conversion.confirm_l5') }}</label>
                </div>
                @include('exment::storage-conversion.attestation', ['codes' => $attestationCodes])
                <button type="submit" class="btn btn-danger" {{ $disabled }}>{{ exmtrans('storage_conversion.action_cutover') }}</button>
            </form>
        @endif
        @if(in_array($state, ['dualwrite', 'backfilling', 'verifying', 'awaiting_confirmation'], true))
            <form method="POST" action="{{ $actionBase }}/cancel" class="mb-3 sc-action--warn">{{ csrf_field() }}
                <button type="submit" class="btn btn-warning" {{ $disabled }}>{{ exmtrans('storage_conversion.action_cancel') }}</button>
            </form>
        @endif
        @if($state === 'initializing')
            <div class="alert alert-warning">{{ exmtrans('storage_conversion.initializing_help') }}</div>
            <form method="POST" action="{{ $actionBase }}/initialize" class="mb-3">{{ csrf_field() }}
                <button type="submit" class="btn btn-primary" {{ $disabled }}>{{ exmtrans('storage_conversion.action_initialize') }}</button>
            </form>
            <form method="POST" action="{{ $actionBase }}/cancel_initialize" class="mb-3">{{ csrf_field() }}
                <p class="text-muted">{{ exmtrans('storage_conversion.cancel_initialize_help') }}</p>
                <button type="submit" class="btn btn-warning" {{ $disabled }}>{{ exmtrans('storage_conversion.action_cancel_initialize') }}</button>
            </form>
        @endif
        @if($state === 'physical_read')
            <form method="POST" action="{{ $actionBase }}/reconcile" class="mb-3">{{ csrf_field() }}
                <p class="text-muted">{{ exmtrans('storage_conversion.reconcile_help') }}</p>
                <button type="submit" class="btn btn-default" {{ $disabled }}>{{ exmtrans('storage_conversion.action_reconcile') }}</button>
            </form>
            @if(empty($status['runs']))
                <p class="text-muted">{{ exmtrans('storage_conversion.direct_physical_help') }}</p>
            @endif
            <form method="POST" action="{{ $actionBase }}/rollback" class="mb-3 sc-action--warn">{{ csrf_field() }}
                <p class="text-muted">{{ exmtrans('storage_conversion.rollback_help') }}</p>
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="accept_json_as_source" name="accept_json_as_source" value="1">
                    <label class="form-check-label" for="accept_json_as_source">{{ exmtrans('storage_conversion.accept_json_as_source') }}</label>
                </div>
                @include('exment::storage-conversion.attestation', ['codes' => $attestationCodes])
                <button type="submit" class="btn btn-warning" {{ $disabled }}>{{ exmtrans('storage_conversion.action_rollback') }}</button>
            </form>
        @endif
    </div>
</div>

{{-- Provisioning completeness (from the live schema) --}}
@if($state === 'provisioned' && empty($status['provision_complete']))
<div class="alert alert-warning">{{ exmtrans('storage_conversion.provision_incomplete') }}</div>
@endif

{{-- Bounded automatic indexes --}}
@if(!empty($status['auto_index']) && empty($status['auto_index']['error']))
@php $ai = $status['auto_index']; @endphp
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.auto_index') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted">{{ exmtrans('storage_conversion.auto_index_help') }}</p>
        @if(!$ai['enabled'])
            <div class="alert alert-info">{{ exmtrans('storage_conversion.auto_index_disabled') }}</div>
        @else
            <p>{{ exmtrans('storage_conversion.auto_index_budget') }}: {{ $ai['existing'] }} / {{ $ai['limit'] }} ({{ exmtrans('storage_conversion.auto_index_engine_max') }} {{ $ai['engine_max'] }})</p>
        @endif
        @if(!empty($ai['candidates']))
        <table class="table table-sm">
            <thead><tr><th>{{ exmtrans('storage_conversion.col_column') }}</th><th>{{ exmtrans('storage_conversion.col_index') }}</th><th>{{ exmtrans('storage_conversion.col_status') }}</th></tr></thead>
            <tbody>
            @foreach($ai['candidates'] as $c)
                <tr><td>{{ $c['column'] }}</td><td>{{ $c['index_name'] }}</td><td>{{ exmtrans('storage_conversion.index_' . $c['status']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @endif
        @if(!empty($status['index_outcomes']))
        <h4 class="fs-6">{{ exmtrans('storage_conversion.auto_index_outcomes') }}</h4>
        <ul>
            @foreach($status['index_outcomes'] as $o)
                <li>{{ $o['finished_at'] ?? $o['status'] }}: {{ $o['index_name'] }} - {{ $sc('status_text', $o['result'] ?? $o['status']) }}@if($o['reason']) ({{ $sc('status_text', $o['reason']) }})@endif</li>
            @endforeach
        </ul>
        @endif
        @if($state === 'physical_read' && $ai['enabled'])
            <form method="POST" action="{{ $actionBase }}/auto_index">{{ csrf_field() }}
                <button type="submit" class="btn btn-default" {{ $disabled }}>{{ exmtrans('storage_conversion.action_auto_index') }}</button>
            </form>
        @endif
    </div>
</div>
@endif

{{-- Lifecycle --}}
@if(!empty($status['transitions']))
<div class="box card p-3 mb-3">
    <div class="box-header with-border border-bottom pb-2 mb-3">
        <h3 class="box-title fs-5 m-0">{{ exmtrans('storage_conversion.lifecycle') }}</h3>
    </div>
    <div class="box-body table-responsive">
        <table class="table table-sm">
            <tbody>
            @foreach($status['transitions'] as $t)
                <tr><td>{{ $t['created_at'] }}</td><td>{{ $t['from_state'] }} &rarr; {{ $t['to_state'] }}</td><td>{{ $t['from_generation'] }} &rarr; {{ $t['to_generation'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Execution boundary: shown on every page, so the screen never suggests an action can run when it cannot. --}}
@if(!$execution['available'])
<div class="alert alert-warning">
    <strong>{{ exmtrans('storage_conversion.execution_unavailable') }}</strong>
    <div>{{ exmtrans('storage_conversion.execution_unavailable_help') }}</div>
    <ul class="mb-0">
        @foreach($execution['missing'] as $code)
            <li>{{ exmtrans('storage_conversion.missing_' . $code) }}</li>
        @endforeach
    </ul>
</div>
@endif
@if(!$execution['installed'])
<div class="alert alert-info">{{ exmtrans('storage_conversion.not_installed') }}</div>
@endif
<p class="text-muted">{{ exmtrans('storage_conversion.driver') }}: {{ $execution['driver'] }}</p>

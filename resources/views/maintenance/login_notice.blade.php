{{-- Maintenance mode (ISS01396) on the login page: the message and the planned end, before a password is typed --}}
@php
    try {
        $maintenanceNow = \Exceedone\Exment\Services\MaintenanceMode::current();
        $maintenancePlan = isset($maintenanceNow) ? null : \Exceedone\Exment\Services\MaintenanceMode::upcoming();
    } catch (\Throwable $e) {
        $maintenanceNow = $maintenancePlan = null;
    }
    $maintenanceAt = function ($at) {
        try {
            return isset($at) ? \Carbon\Carbon::parse($at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
        } catch (\Throwable $e) {
            return null;
        }
    };
@endphp
@if(isset($maintenanceNow))
<div class="alert alert-warning exment-maintenance-login" role="status">
    <strong>{{ exmtrans('maintenance.title') }}</strong>
    <div style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ \Exceedone\Exment\Services\MaintenanceMode::message($maintenanceNow) }}</div>
    @if($maintenanceAt($maintenanceNow['end_at'] ?? null))
    <div>{{ exmtrans('maintenance.end_at', ['end_at' => $maintenanceAt($maintenanceNow['end_at'])]) }}</div>
    @endif
    <div class="small">{{ exmtrans('maintenance.login_admins_only') }}</div>
</div>
@elseif(isset($maintenancePlan))
<div class="alert alert-info exment-maintenance-login" role="status">
    {{ exmtrans('maintenance.login_planned', ['start_at' => $maintenanceAt($maintenancePlan['start_at']) ?? '-', 'end_at' => $maintenanceAt($maintenancePlan['end_at']) ?? '-']) }}
</div>
@endif

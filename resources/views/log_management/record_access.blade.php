{{-- Who read and who changed a record (ISS02905): AuditReportController::record / recordAccessBox --}}
<div class="box box-default" id="record-access">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('audit_report.record_access.header') }}@if($title) : {{ $title }}@endif</h3>
        @if($recordUrl)
            <div class="box-tools"><a href="{{ $recordUrl }}" class="btn btn-default btn-sm">{{ exmtrans('audit_report.record_access.back') }}</a></div>
        @endif
    </div>
    <div class="box-body">
        @if(!$readsKept)
            <p class="text-muted small">{{ exmtrans('audit_report.note.reads_not_recorded') }}</p>
        @endif
        <div class="table-responsive">
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('audit_report.column.created_at') }}</th>
                    <th>{{ exmtrans('audit_report.record_access.user') }}</th>
                    <th>{{ exmtrans('audit_report.record_access.kind') }}</th>
                    <th>{{ exmtrans('audit_report.record_access.via') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td style="white-space:nowrap">{{ $row['at'] }}</td>
                    <td>{{ $row['user'] }}</td>
                    <td>{{ $row['kind'] }}</td>
                    <td>{{ $row['via'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">{{ exmtrans('audit_report.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        @if($moreUrl)
            <a href="{{ $moreUrl }}" class="btn btn-default btn-sm">{{ exmtrans('audit_report.record_access.more') }}</a>
        @endif
        @if($prevUrl) <a href="{{ $prevUrl }}" class="btn btn-default btn-sm">{{ exmtrans('audit_report.record_access.prev') }}</a> @endif
        @if($nextUrl) <a href="{{ $nextUrl }}" class="btn btn-default btn-sm">{{ exmtrans('audit_report.record_access.next') }}</a> @endif
    </div>
</div>

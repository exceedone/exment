{{-- The IT audit report (ISS02840): AuditReportController --}}
<div class="box box-default" id="audit-report-filter">
    <div class="box-body">
        <form method="get" action="{{ $url }}" class="form-inline" pjax-container>
            <label for="audit-from">{{ exmtrans('audit_report.period') }}</label>
            <input type="date" class="form-control input-sm" id="audit-from" name="from" value="{{ $report->from()->format('Y-m-d') }}">
            ～
            <input type="date" class="form-control input-sm" name="to" value="{{ $report->to()->format('Y-m-d') }}" aria-label="{{ exmtrans('audit_report.period') }}">
            <label for="audit-inactive-days" style="margin-left:12px">{{ exmtrans('audit_report.inactive_days') }}</label>
            <input type="number" min="1" max="3650" class="form-control input-sm" id="audit-inactive-days" name="inactive_days" value="{{ $report->inactiveDays() }}" style="width:90px">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('audit_report.show') }}</button>
            <a href="{{ $pdfUrl }}" class="btn btn-default btn-sm" data-pjax="false" target="_blank" id="audit-report-pdf"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> PDF</a>
            <a href="{{ $logUrl }}" class="btn btn-default btn-sm"><i class="fa fa-archive" aria-hidden="true"></i> {{ exmtrans('log_management.header') }}</a>
        </form>
        <p class="text-muted small" style="margin:8px 0 0">
            {{ \Exceedone\Exment\Controllers\AuditReportController::loginLogNote($report) }}
            @if($ipMode !== 'keep') {{ exmtrans('audit_report.ip_mode_note') }} @endif
            {{ exmtrans('audit_report.export_logged') }}
        </p>
    </div>
</div>

@foreach($sections as $section)
<div class="box box-default" id="audit-report-{{ $section['key'] }}">
    <div class="box-header with-border">
        <h3 class="box-title">{{ $section['title'] }} <small>({{ number_format(count($section['rows'])) }})</small></h3>
        <div class="box-tools">
            <a href="{{ $csvUrl($section['key']) }}" class="btn btn-default btn-xs" data-pjax="false" target="_blank"><i class="fa fa-download" aria-hidden="true"></i> CSV</a>
        </div>
    </div>
    <div class="box-body">
        @if(isset($section['note']))
            <p class="text-muted small">{{ $section['note'] }}</p>
        @endif
        @if(count($section['rows']) === 0)
            <p class="text-muted">{{ exmtrans('audit_report.none') }}</p>
        @else
            <div class="table-responsive">
            <table class="table table-condensed table-striped">
                <thead>
                    <tr>
                    @foreach($section['columns'] as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach(array_slice($section['rows'], 0, $pageRows) as $row)
                    <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @if(count($section['rows']) > $pageRows)
                <p class="text-muted small">{{ exmtrans('audit_report.more_in_csv', ['count' => $pageRows, 'total' => count($section['rows'])]) }}</p>
            @endif
        @endif
    </div>
</div>
@endforeach

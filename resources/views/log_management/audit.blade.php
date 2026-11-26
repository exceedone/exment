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
            <input type="text" class="form-control input-sm" name="data_table" value="{{ $dataTable }}" placeholder="{{ exmtrans('audit_report.filter_table') }}" style="width:140px">
            <input type="text" class="form-control input-sm" name="data_user" value="{{ $dataUser }}" placeholder="{{ exmtrans('audit_report.filter_user') }}" style="width:140px">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('audit_report.show') }}</button>
            <a href="{{ $pdfUrl }}" class="btn btn-default btn-sm" data-pjax="false" target="_blank" id="audit-report-pdf"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> PDF</a>
            <a href="{{ $logUrl }}" class="btn btn-default btn-sm"><i class="fa fa-archive" aria-hidden="true"></i> {{ exmtrans('log_management.header') }}</a>
        </form>
        <p class="text-muted small" style="margin:8px 0 0">
            {{ $loginLogNote }}
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

<div class="box box-default" id="audit-report-files">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('audit_report.files_header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted small">{{ exmtrans('audit_report.files_help') }}</p>
        <form method="post" action="{{ $verifyUrl }}" enctype="multipart/form-data" class="form-inline" style="margin-bottom:8px">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <label for="audit-verify">{{ exmtrans('audit_report.verify') }}</label>
            <input type="file" id="audit-verify" name="report" accept="application/pdf" class="form-control input-sm">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-check" aria-hidden="true"></i> {{ exmtrans('audit_report.verify_button') }}</button>
        </form>
        @if(count($files) === 0)
            <p class="text-muted">{{ exmtrans('audit_report.none') }}</p>
        @else
        <div class="table-responsive">
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('audit_report.made_at') }}</th>
                    <th>{{ exmtrans('audit_report.period') }}</th>
                    <th>{{ exmtrans('audit_report.made_by') }}</th>
                    <th>SHA-256</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach($files as $file)
                <tr>
                    <td style="white-space:nowrap">{{ optional($file->created_at)->format('Y-m-d H:i') }}</td>
                    <td style="white-space:nowrap">{{ $file->period_from->format('Y-m-d') }} - {{ $file->period_to->format('Y-m-d') }}</td>
                    <td>{{ $userName($file->made_user_id) }}</td>
                    <td><code class="small">{{ $file->sha256 }}</code></td>
                    <td>
                        @if(!is_null(\Exceedone\Exment\Services\AuditReport\AuditReportStore::path($file)))
                            <a href="{{ $fileUrl }}/{{ $file->id }}" class="btn btn-default btn-xs" data-pjax="false" target="_blank"><i class="fa fa-download" aria-hidden="true"></i> PDF</a>
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

@if($isSystem)
<div class="box box-default" id="audit-report-settings">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('audit_report.settings_header') }}</h3>
    </div>
    <form method="post" action="{{ $settingUrl }}" class="form-horizontal" pjax-container>
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-3 control-label" for="audit_report_schedule">{{ exmtrans('audit_report.schedule') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="audit_report_schedule" name="audit_report_schedule">
                    @foreach($schedules as $option)
                        <option value="{{ $option }}" @if($schedule === $option) selected @endif>{{ exmtrans('audit_report.schedule_option.' . $option) }}</option>
                    @endforeach
                    </select>
                </div>
                <div class="col-sm-6 help-block">{{ exmtrans('audit_report.schedule_help') }}</div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="audit_report_mail" value="1" @if($mail) checked @endif> {{ exmtrans('audit_report.mail') }}</label>
                    <p class="help-block">{{ exmtrans('audit_report.mail_help') }}</p>
                </div>
            </div>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary pull-right">{{ trans('admin.save') }}</button>
        </div>
    </form>
</div>
@endif

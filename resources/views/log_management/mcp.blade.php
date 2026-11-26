{{-- The log of the MCP calls (ISS02915): McpLogController --}}
<p>
    <a href="{{ $logUrl }}" class="btn btn-default btn-sm"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ exmtrans('log_management.header') }}</a>
</p>

@if(!$active)
<div class="callout callout-info" id="mcp-log-off">
    <p>{{ exmtrans('mcp_log.off') }} <a href="{{ $featureUrl }}">{{ exmtrans('mcp_log.feature_link') }}</a></p>
</div>
@endif

<div class="box box-default" id="mcp-log-rows">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('mcp_log.header') }}</h3>
    </div>
    <div class="box-body">
        <form method="get" action="{{ $url }}" class="form-inline" style="margin-bottom:8px" pjax-container>
            <input type="text" class="form-control input-sm" name="from" value="{{ $filter['from'] }}" placeholder="YYYY-MM-DD HH:MM" aria-label="{{ exmtrans('log_management.filter.from') }}">
            ～
            <input type="text" class="form-control input-sm" name="to" value="{{ $filter['to'] }}" placeholder="YYYY-MM-DD HH:MM" aria-label="{{ exmtrans('log_management.filter.to') }}">
            <input type="text" class="form-control input-sm" name="user" value="{{ $filter['user'] }}" placeholder="{{ exmtrans('mcp_log.filter.user') }}">
            <select class="form-control input-sm" name="tool" aria-label="{{ exmtrans('mcp_log.column.tool') }}">
                <option value="">{{ exmtrans('mcp_log.filter.all_tools') }}</option>
                @foreach($tools as $tool)
                    <option value="{{ $tool }}" @if($filter['tool'] === $tool) selected @endif>{{ $tool }}</option>
                @endforeach
            </select>
            <select class="form-control input-sm" name="status" aria-label="{{ exmtrans('mcp_log.column.status') }}">
                <option value="">{{ exmtrans('mcp_log.filter.all_statuses') }}</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @if($filter['status'] === $status) selected @endif>{{ exmtrans('mcp_log.status.' . $status) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ trans('admin.filter') }}</button>
        </form>
        <p class="text-muted small">{{ exmtrans('mcp_log.help', ['days' => $retention ?? '-']) }}</p>
        <div class="table-responsive">
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('log_management.column.datetime') }}</th>
                    <th>{{ exmtrans('mcp_log.column.user') }}</th>
                    <th>{{ exmtrans('mcp_log.column.tool') }}</th>
                    <th>{{ exmtrans('mcp_log.column.status') }}</th>
                    <th>{{ exmtrans('mcp_log.column.duration') }}</th>
                    <th>{{ exmtrans('mcp_log.column.size') }}</th>
                    <th>{{ exmtrans('mcp_log.column.args') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr data-mcp-log="{{ $row->id }}">
                    <td style="white-space:nowrap">{{ optional($row->created_at)->format('Y/m/d H:i:s') }}</td>
                    <td>{{ $userName($row->base_user_id) }}<br><span class="text-muted small">{{ $row->ip }}</span></td>
                    <td class="small">{{ $row->tool ?? $row->method }}</td>
                    <td>
                        <span class="label {{ $row->status === 'ok' ? 'label-success' : ($row->status === 'refused' ? 'label-warning' : 'label-danger') }}">{{ exmtrans('mcp_log.status.' . $row->status) }}</span>
                        @if($row->reason) <div class="small" style="word-break:break-all">{{ $row->reason }}@if(!is_null($row->rpc_code)) ({{ $row->rpc_code }})@endif</div> @endif
                    </td>
                    <td class="small" style="white-space:nowrap">@if(!is_null($row->duration_ms)){{ exmtrans('mcp_log.duration', ['ms' => number_format($row->duration_ms)]) }}@endif</td>
                    <td class="small" style="white-space:nowrap">@if(!is_null($row->result_bytes)){{ exmtrans('mcp_log.bytes', ['bytes' => number_format($row->result_bytes)]) }}@endif</td>
                    <td class="small" style="word-break:break-all">{{ $row->args }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">{{ exmtrans('mcp_log.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div>
            @if($prevUrl) <a href="{{ $prevUrl }}" class="btn btn-default btn-sm">{{ exmtrans('mcp_log.prev') }}</a> @endif
            @if($nextUrl) <a href="{{ $nextUrl }}" class="btn btn-default btn-sm">{{ exmtrans('mcp_log.next') }}</a> @endif
        </div>
    </div>
</div>

@if($isSystem)
<div class="box box-default" id="mcp-log-setting">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('mcp_log.setting_header') }}</h3>
    </div>
    <form method="post" action="{{ $settingUrl }}" class="form-horizontal" pjax-container>
        {{ csrf_field() }}
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_mcp_log">{{ exmtrans('log_management.column.retention') }}</label>
                <div class="col-sm-3">
                    <input type="number" min="0" class="form-control" id="log_retention_mcp_log" name="log_retention_mcp_log" value="{{ $retentionSetting }}" placeholder="{{ $defaultDays }}">
                </div>
                <div class="col-sm-5 help-block">{{ exmtrans('mcp_log.retention_help', ['days' => $defaultDays]) }}</div>
            </div>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary">{{ trans('admin.save') }}</button>
        </div>
    </form>
</div>
@endif

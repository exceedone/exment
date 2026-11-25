{{-- The log of the browsers (ISS02904): BrowserLogController --}}
<p>
    <a href="{{ $logUrl }}" class="btn btn-default btn-sm"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ exmtrans('log_management.header') }}</a>
</p>

@if(!$active)
<div class="callout callout-info" id="browser-log-off">
    <p>{{ exmtrans('browser_log.off') }} <a href="{{ $featureUrl }}">{{ exmtrans('browser_log.feature_link') }}</a></p>
</div>
@endif

<div class="box box-default" id="browser-log-rows">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('browser_log.header') }}</h3>
    </div>
    <div class="box-body">
        <form method="get" action="{{ $url }}" class="form-inline" style="margin-bottom:8px" pjax-container>
            <input type="text" class="form-control input-sm" name="from" value="{{ $filter['from'] }}" placeholder="YYYY-MM-DD HH:MM" aria-label="{{ exmtrans('log_management.filter.from') }}">
            ～
            <input type="text" class="form-control input-sm" name="to" value="{{ $filter['to'] }}" placeholder="YYYY-MM-DD HH:MM" aria-label="{{ exmtrans('log_management.filter.to') }}">
            <input type="text" class="form-control input-sm" name="user" value="{{ $filter['user'] }}" placeholder="{{ exmtrans('browser_log.filter.user') }}">
            <select class="form-control input-sm" name="kind" aria-label="{{ exmtrans('browser_log.column.kind') }}">
                <option value="">{{ exmtrans('browser_log.filter.all_kinds') }}</option>
                @foreach($kinds as $kind)
                    <option value="{{ $kind }}" @if($filter['kind'] === $kind) selected @endif>{{ exmtrans('browser_log.kind.' . $kind) }}</option>
                @endforeach
            </select>
            <input type="text" class="form-control input-sm" name="q" value="{{ $filter['q'] }}" placeholder="{{ exmtrans('browser_log.filter.q') }}">
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ trans('admin.filter') }}</button>
        </form>
        <p class="text-muted small">{{ exmtrans('browser_log.help', ['days' => $retention ?? '-']) }}</p>
        <div class="table-responsive">
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('log_management.column.datetime') }}</th>
                    <th>{{ exmtrans('browser_log.column.user') }}</th>
                    <th>{{ exmtrans('browser_log.column.kind') }}</th>
                    <th>{{ exmtrans('browser_log.column.path') }}</th>
                    <th>{{ exmtrans('browser_log.column.detail') }}</th>
                    <th>{{ exmtrans('browser_log.column.browser') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $row)
                <tr data-browser-log="{{ $row->id }}">
                    <td style="white-space:nowrap">{{ optional($row->created_at)->format('Y/m/d H:i:s') }}</td>
                    <td>{{ $userName($row->base_user_id) }}</td>
                    <td>
                        <span class="label {{ $row->kind === 'page' ? 'label-default' : 'label-danger' }}">{{ exmtrans('browser_log.kind.' . $row->kind) }}</span>
                        @if($row->count > 1) <span class="small">×{{ $row->count }}</span> @endif
                    </td>
                    <td class="small" style="word-break:break-all">{{ $row->path }}</td>
                    <td class="small" style="word-break:break-all">
                        @if($row->kind === 'request')
                            {{ exmtrans('browser_log.status', ['status' => $row->status]) }}
                        @endif
                        @if(!is_null($row->duration_ms))
                            {{ exmtrans('browser_log.duration', ['ms' => number_format($row->duration_ms)]) }}
                        @endif
                        @if($row->message) <div>{{ $row->message }}</div> @endif
                        @if($row->source) <div class="text-muted">{{ $row->source }}</div> @endif
                    </td>
                    <td class="small">{{ $row->browser }} / {{ $row->os }}<br>{{ $row->screen }} {{ $row->lang }}<br><span class="text-muted" title="{{ exmtrans('browser_log.column.tab') }}">{{ $row->tab }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">{{ exmtrans('browser_log.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div>
            @if($prevUrl) <a href="{{ $prevUrl }}" class="btn btn-default btn-sm">{{ exmtrans('browser_log.prev') }}</a> @endif
            @if($nextUrl) <a href="{{ $nextUrl }}" class="btn btn-default btn-sm">{{ exmtrans('browser_log.next') }}</a> @endif
        </div>
    </div>
</div>

@if($isSystem)
<div class="box box-default" id="browser-log-setting">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('browser_log.setting_header') }}</h3>
    </div>
    <form method="post" action="{{ $settingUrl }}" class="form-horizontal" pjax-container>
        {{ csrf_field() }}
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ exmtrans('browser_log.pages') }}</label>
                <div class="col-sm-8">
                    <div class="checkbox"><label><input type="checkbox" name="browser_log_pages" value="1" @if($settings['browser_log_pages']) checked @endif> {{ exmtrans('browser_log.pages_help') }}</label></div>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_browser_log">{{ exmtrans('log_management.column.retention') }}</label>
                <div class="col-sm-3">
                    <input type="number" min="0" class="form-control" id="log_retention_browser_log" name="log_retention_browser_log" value="{{ $settings['log_retention_browser_log'] }}" placeholder="{{ $defaultDays }}">
                </div>
                <div class="col-sm-5 help-block">{{ exmtrans('browser_log.retention_help', ['days' => $defaultDays]) }}</div>
            </div>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary">{{ trans('admin.save') }}</button>
        </div>
    </form>
</div>
@endif

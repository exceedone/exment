{{-- The log management (ISS02839): LogManagementController --}}
@if(!empty($sizeWarning))
<div class="callout callout-warning" id="log-management-size-warning"><p>{{ $sizeWarning }}</p></div>
@endif
<p>
    <a href="{{ admin_urls('log_management', 'audit') }}" class="btn btn-default btn-sm" id="log-management-audit-report"><i class="fa fa-clipboard" aria-hidden="true"></i> {{ exmtrans('audit_report.header') }}</a>
</p>
<div class="box box-default" id="log-management-types">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('log_management.types_header') }}</h3>
    </div>
    <div class="box-body table-responsive">
        <table class="table table-condensed">
            <thead>
                <tr>
                    <th>{{ exmtrans('log_management.column.type') }}</th>
                    <th>{{ exmtrans('log_management.column.retention') }}</th>
                    <th>{{ exmtrans('log_management.column.count') }}</th>
                    <th>{{ exmtrans('log_management.column.size') }}</th>
                    <th>{{ exmtrans('log_management.column.location') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($types as $type)
                <tr data-log-type="{{ $type['key'] }}">
                    <td>
                        {{ trans($type['label']) }}
                        @if($type['key'] === 'browser_log')
                            <a href="{{ admin_urls('log_management', 'browser') }}" class="small">{{ exmtrans('browser_log.open') }}</a>
                        @endif
                        @if($type['key'] === 'mcp_log')
                            <a href="{{ admin_urls('log_management', 'mcp') }}" class="small">{{ exmtrans('mcp_log.open') }}</a>
                        @endif
                    </td>
                    <td>
                        @if(is_null($type['retention_days']))
                            {{ exmtrans('log_management.kept') }}
                        @else
                            {{ exmtrans('log_management.days', ['days' => $type['retention_days']]) }}
                        @endif
                        @if($type['managed_by'] === 'exment:schedule')
                            <a href="{{ $operationLogUrl }}" class="small">{{ exmtrans('log_management.operation_log_setting') }}</a>
                        @endif
                    </td>
                    <td>{{ is_null($type['count']) ? '-' : number_format($type['count']) }}</td>
                    <td>{{ $bytes($type['size_bytes']) }}</td>
                    <td><code>{{ $type['location'] }}</code></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($isSystem)
<div class="box box-default" id="log-management-settings">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('log_management.settings_header') }}</h3>
    </div>
    <form method="post" action="{{ $settingUrl }}" class="form-horizontal" pjax-container>
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <div class="box-body">
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_login_log">{{ exmtrans('log_management.setting.login_log_days') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_retention_login_log" name="log_retention_login_log" value="{{ old('log_retention_login_log', $settings['log_retention_login_log']) }}" placeholder="{{ $defaults['login_log'] }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.days_help', ['default' => $defaults['login_log']]) }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_permission_change">{{ exmtrans('log_management.setting.permission_change_days') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_retention_permission_change" name="log_retention_permission_change" value="{{ old('log_retention_permission_change', $settings['log_retention_permission_change']) }}" placeholder="{{ $defaults['permission_change'] }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.days_help', ['default' => $defaults['permission_change']]) }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_data_access">{{ exmtrans('log_management.setting.data_access_days') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_retention_data_access" name="log_retention_data_access" value="{{ old('log_retention_data_access', $settings['log_retention_data_access']) }}" placeholder="{{ $defaults['data_access'] }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.days_help', ['default' => $defaults['data_access']]) }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_data_access_daily">{{ exmtrans('log_management.setting.data_access_daily_days') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_retention_data_access_daily" name="log_retention_data_access_daily" value="{{ old('log_retention_data_access_daily', $settings['log_retention_data_access_daily']) }}" placeholder="{{ $defaults['data_access_daily'] }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.days_help', ['default' => $defaults['data_access_daily']]) }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_retention_app_log">{{ exmtrans('log_management.setting.app_log_days') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_retention_app_log" name="log_retention_app_log" value="{{ old('log_retention_app_log', $settings['log_retention_app_log']) }}" placeholder="{{ $defaults['app_log'] }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.days_help', ['default' => $defaults['app_log']]) }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_app_level">{{ exmtrans('log_management.setting.app_level') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="log_app_level" name="log_app_level">
                        <option value="">{{ exmtrans('log_management.setting.app_level_config') }}</option>
                        @foreach($levels as $level)
                            <option value="{{ $level }}" @if($settings['log_app_level'] === $level) selected @endif>{{ $level }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.setting.app_level_help') }}</div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="log_app_daily" value="1" @if($settings['log_app_daily']) checked @endif> {{ exmtrans('log_management.setting.app_daily') }}</label>
                    <p class="help-block">{{ exmtrans('log_management.setting.app_daily_help') }}</p>
                </div>
            </div>
            <h4 class="col-sm-offset-3" style="padding-left:15px">{{ exmtrans('log_management.privacy.header') }}</h4>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="log_store_input" value="1" @if($settings['log_store_input']) checked @endif> {{ exmtrans('log_management.privacy.store_input') }}</label>
                    <p class="help-block">{{ exmtrans('log_management.privacy.store_input_help') }}</p>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_ip_mode">{{ exmtrans('log_management.privacy.ip_mode') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="log_ip_mode" name="log_ip_mode">
                        @foreach($ipModes as $mode)
                            <option value="{{ $mode }}" @if($settings['log_ip_mode'] === $mode) selected @endif>{{ exmtrans('log_management.privacy.ip_' . $mode) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.privacy.ip_help') }}</div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="mail_log_no_body" value="1" @if($settings['mail_log_no_body']) checked @endif> {{ exmtrans('log_management.privacy.mail_body') }}</label>
                    <p class="help-block">{{ exmtrans('log_management.privacy.mail_body_help') }}</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="log_archive_encrypt" value="1" @if($settings['log_archive_encrypt']) checked @endif> {{ exmtrans('log_management.privacy.archive_encrypt') }}</label>
                    <p class="help-block">{{ exmtrans('log_management.privacy.archive_encrypt_help') }}</p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-sm-offset-3 col-sm-9">
                    <label><input type="checkbox" name="log_archive_enabled" value="1" @if($settings['log_archive_enabled']) checked @endif> {{ exmtrans('log_management.archive.enabled') }}</label>
                    <p class="help-block">{{ exmtrans('log_management.archive.enabled_help') }}</p>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_archive_driver">{{ exmtrans('log_management.archive.driver') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="log_archive_driver" name="log_archive_driver">
                        @foreach($drivers as $driver)
                            <option value="{{ $driver }}" @if($settings['log_archive_driver'] === $driver) selected @endif>{{ exmtrans('log_management.archive.driver_' . $driver) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="log_archive_years">{{ exmtrans('log_management.archive.years') }}</label>
                <div class="col-sm-3"><input type="number" min="0" class="form-control" id="log_archive_years" name="log_archive_years" value="{{ old('log_archive_years', $settings['log_archive_years']) }}"></div>
                <div class="col-sm-6 help-block">{{ exmtrans('log_management.archive.years_help') }}</div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label" for="operation_log_scope">{{ exmtrans('log_management.setting.operation_log_scope') }}</label>
                <div class="col-sm-3">
                    <select class="form-control" id="operation_log_scope" name="operation_log_scope">
                        @foreach($scopes as $scope)
                            <option value="{{ $scope }}" @if($settings['operation_log_scope'] === $scope) selected @endif>{{ exmtrans('log_management.setting.scope_' . $scope) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="box-footer">
            <div class="col-sm-offset-3"><button type="submit" class="btn btn-primary">{{ trans('admin.save') }}</button></div>
        </div>
    </form>
</div>
@endif

<div class="box box-default" id="log-management-logins">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('log_management.logins_header') }}</h3>
    </div>
    <div class="box-body">
        <form method="get" class="form-inline" style="margin-bottom:8px" pjax-container>
            <input type="date" class="form-control input-sm" name="from" value="{{ $filter['from'] }}" aria-label="{{ exmtrans('log_management.filter.from') }}">
            ～
            <input type="date" class="form-control input-sm" name="to" value="{{ $filter['to'] }}" aria-label="{{ exmtrans('log_management.filter.to') }}">
            <input type="text" class="form-control input-sm" name="name" value="{{ $filter['name'] }}" placeholder="{{ exmtrans('log_management.filter.name') }}">
            <select class="form-control input-sm" name="result" aria-label="{{ exmtrans('log_management.column.result') }}">
                <option value="">{{ exmtrans('log_management.filter.all_results') }}</option>
                @foreach(\Exceedone\Exment\Model\LoginLog::RESULTS as $result)
                    <option value="{{ $result }}" @if($filter['result'] === $result) selected @endif>{{ exmtrans('log_management.result.' . $result) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ trans('admin.filter') }}</button>
            <a href="{{ $csvUrl }}" class="btn btn-default btn-sm" data-pjax="false" target="_blank"><i class="fa fa-download" aria-hidden="true"></i> CSV</a>
        </form>
        <p class="text-muted small">{{ exmtrans('log_management.logins_help', ['count' => $pageRows]) }}</p>
        <div class="table-responsive">
        <table class="table table-condensed table-striped">
            <thead>
                <tr>
                    <th>{{ exmtrans('log_management.column.datetime') }}</th>
                    <th>{{ exmtrans('log_management.column.result') }}</th>
                    <th>{{ exmtrans('log_management.column.login_name') }}</th>
                    <th>{{ exmtrans('log_management.column.provider') }}</th>
                    <th>IP</th>
                    <th>{{ exmtrans('log_management.column.user_agent') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($logins as $login)
                <tr>
                    <td style="white-space:nowrap">{{ optional($login->created_at)->format('Y/m/d H:i:s') }}</td>
                    <td>
                        <span class="label {{ $login->result === 'success' ? 'label-success' : ($login->result === 'logout' ? 'label-default' : ($login->result === 'ip_exempt' ? 'label-warning' : 'label-danger')) }}">{{ exmtrans('log_management.result.' . $login->result) }}</span>
                        @if($login->failure_reason) <span class="small">{{ exmtrans('log_management.reason.' . $login->failure_reason) }}</span> @endif
                    </td>
                    <td>{{ $login->login_name }}</td>
                    <td>{{ $login->provider }}{{ $login->provider_name ? ' (' . $login->provider_name . ')' : '' }}</td>
                    <td>{{ $login->ip }}</td>
                    <td class="small">{{ \Illuminate\Support\Str::limit(strval($login->user_agent), 80) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">{{ exmtrans('log_management.no_logins') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

@if($isSystem)
<div class="box box-default" id="log-management-files">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('log_management.files_header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted small">{{ exmtrans('log_management.files_help') }}</p>
        <table class="table table-condensed">
            <tbody>
            @forelse($files as $file)
                <tr>
                    <td><a href="{{ $fileUrl }}/{{ rawurlencode($file['name']) }}" data-pjax="false" target="_blank">{{ $file['name'] }}</a></td>
                    <td>{{ $bytes($file['size']) }}</td>
                    <td>{{ date('Y/m/d H:i', $file['modified']) }}</td>
                </tr>
            @empty
                <tr><td class="text-muted">{{ exmtrans('log_management.no_files') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

@if($isSystem)
<div class="box box-default" id="log-management-archives">
    <div class="box-header with-border">
        <h3 class="box-title">{{ exmtrans('log_management.archive.header') }}</h3>
    </div>
    <div class="box-body">
        <p class="text-muted small">{{ exmtrans('log_management.archive.help') }}</p>
        <table class="table table-condensed">
            <tbody>
            @forelse($archives as $path => $archive)
                <tr>
                    <td><a href="{{ $archiveUrl }}/{{ $archive['kind'] }}/{{ rawurlencode(basename($path)) }}" data-pjax="false" target="_blank">{{ basename($path) }}</a></td>
                    <td>{{ $archive['month'] }}</td>
                    <td>{{ $bytes($archive['size']) }}</td>
                </tr>
            @empty
                <tr><td class="text-muted">{{ exmtrans('log_management.archive.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

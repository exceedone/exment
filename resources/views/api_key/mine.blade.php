{{-- The API keys of the login user. Data from UserApiKeyController::index. --}}
<div class="exm-api-key">
    @if (isset($new_key))
        <div class="alert alert-success">
            <p><strong>{{ exmtrans('api_key.new_key') }}</strong></p>
            <input type="text" class="form-control" readonly value="{{ $new_key }}" onfocus="this.select()" style="font-family:monospace">
            <p style="margin-top:6px">{{ exmtrans('api_key.new_key_help') }}</p>
        </div>
    @endif
    @if (isset($error))
        <div class="alert alert-danger">{{ $error }}
            @if (isset($error_link)) <a href="{{ $error_link }}">{{ exmtrans('api_key.sign_in_again_link') }}</a>@endif
        </div>
    @endif

    <div class="callout callout-info"><p>{{ exmtrans('api_key.help') }}</p></div>

    <div class="box card">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('api_key.my_keys') }}</h3></div>
        <div class="box-body">
            @include('exment::api_key._list', ['keys' => $keys, 'admin' => false, 'read_only' => $read_only,
                'revoke_url' => function ($id) use ($action) { return url_join($action, $id, 'revoke'); }])
        </div>
    </div>

    @if (!$read_only)
    <div class="box card">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('api_key.create') }}</h3></div>
        <form method="post" action="{{ $action }}" class="form-horizontal" autocomplete="off">
            {{ csrf_field() }}
            <div class="box-body">
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.name') }}</label>
                    <div class="col-sm-8"><input type="text" name="name" class="form-control" required maxlength="256" value="{{ $old['name'] ?? '' }}"></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.scopes') }}</label>
                    <div class="col-sm-8">
                        <label class="radio-inline"><input type="radio" name="scope_type" value="read_only" {{ ($old['scope_type'] ?? 'read_only') !== 'custom' ? 'checked' : '' }}> {{ exmtrans('api_key.read_only') }}</label>
                        <label class="radio-inline"><input type="radio" name="scope_type" value="custom" {{ ($old['scope_type'] ?? '') === 'custom' ? 'checked' : '' }}> {{ exmtrans('api_key.custom_scopes') }}</label>
                        <div style="margin-top:4px">
                        @foreach ($scopes as $scope)
                            <label class="checkbox-inline" style="margin-left:0;margin-right:10px"><input type="checkbox" name="scopes[]" value="{{ $scope }}" {{ in_array($scope, (array)($old['scopes'] ?? []), true) ? 'checked' : '' }}> {{ $scope }}</label>
                        @endforeach
                        </div>
                        <span class="help-block">{{ exmtrans('api_key.scopes_help', implode(', ', $read_only_scopes)) }}</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.tables') }}</label>
                    <div class="col-sm-8">
                        <label class="radio-inline"><input type="radio" name="table_type" value="all" {{ ($old['table_type'] ?? 'all') !== 'some' ? 'checked' : '' }}> {{ exmtrans('api_key.all_tables') }}</label>
                        <label class="radio-inline"><input type="radio" name="table_type" value="some" {{ ($old['table_type'] ?? '') === 'some' ? 'checked' : '' }}> {{ exmtrans('api_key.some_tables') }}</label>
                        <div style="max-height:160px;overflow:auto;margin-top:4px">
                        @foreach ($tables as $table)
                            <label style="display:block;font-weight:normal;margin:0"><input type="checkbox" name="tables[]" value="{{ $table['name'] }}" {{ in_array($table['name'], (array)($old['tables'] ?? []), true) ? 'checked' : '' }}> {{ $table['label'] }}</label>
                        @endforeach
                        </div>
                        <span class="help-block">{{ exmtrans('api_key.tables_help') }}</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.allowed_ips') }}</label>
                    <div class="col-sm-8">
                        <textarea name="allowed_ips" rows="3" class="form-control" placeholder="192.0.2.10&#10;198.51.100.0/24">{{ $old['allowed_ips'] ?? '' }}</textarea>
                        <span class="help-block">{{ exmtrans('api_key.allowed_ips_help') }}</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.expires_at') }}</label>
                    <div class="col-sm-3">
                        <select name="days" class="form-control">
                        @foreach ($days as $d)
                            <option value="{{ $d }}" {{ intval($old['days'] ?? $default_days) === $d ? 'selected' : '' }}>{{ exmtrans('api_key.days', $d) }}</option>
                        @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.client_cert') }}</label>
                    <div class="col-sm-8">
                        <input type="text" name="client_cert_fingerprint" class="form-control" maxlength="128" value="{{ $old['client_cert_fingerprint'] ?? '' }}" placeholder="SHA-256">
                        <span class="help-block">{{ exmtrans('api_key.client_cert_help') }}</span>
                    </div>
                </div>
                @if ($ask_password)
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('user.current_password') }}</label>
                    <div class="col-sm-8">
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        <span class="help-block">{{ exmtrans('api_key.current_password_help') }}</span>
                    </div>
                </div>
                @endif
                <div class="form-group">
                    <div class="col-sm-2"></div>
                    <div class="col-sm-8"><span class="help-block">{{ exmtrans('api_key.max_keys_help', $max_keys) }}</span></div>
                </div>
            </div>
            <div class="box-footer">
                <div class="col-sm-2"></div>
                <div class="col-sm-8"><button type="submit" class="btn btn-primary">{{ exmtrans('api_key.create_button') }}</button></div>
            </div>
        </form>
    </div>
    @endif
</div>

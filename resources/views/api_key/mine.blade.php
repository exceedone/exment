{{-- The API keys of the login user. Data from UserApiKeyController::index.
     The order: what was just made (shown once), the form to make a key (the purpose first), the keys,
     then how to connect AI (MCP), folded. --}}
<div class="exm-api-key">
    <style>
        .exm-api-key .exm-ak-purposes { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 6px; }
        .exm-api-key .exm-ak-purpose { flex: 1 1 260px; display: flex; gap: 10px; align-items: flex-start; margin: 0; padding: 12px 14px; border: 2px solid #d2d6de; border-radius: 6px; cursor: pointer; font-weight: normal; background: #fff; }
        .exm-api-key .exm-ak-purpose:hover { border-color: #8fb6db; }
        .exm-api-key .exm-ak-purpose.is-checked { border-color: #3c8dbc; background: #f2f8fc; }
        .exm-api-key .exm-ak-purpose input { margin-top: 4px; }
        .exm-api-key .exm-ak-purpose .exm-ak-purpose-title { display: block; font-size: 14px; font-weight: bold; }
        .exm-api-key .exm-ak-help { display: block; color: #777; font-size: 12px; margin-top: 4px; }
        .exm-api-key .exm-ak-copy { white-space: nowrap; }
        .exm-api-key .exm-ak-purpose .exm-ak-purpose-help { display: block; color: #666; font-size: 12px; margin-top: 2px; }
        .exm-api-key .exm-ak-purpose .fa { font-size: 20px; color: #3c8dbc; width: 24px; text-align: center; margin-top: 2px; }
        .exm-api-key .exm-ak-groups { display: flex; flex-wrap: wrap; gap: 6px 24px; }
        .exm-api-key .exm-ak-group { flex: 1 1 260px; }
        .exm-api-key .exm-ak-group-title { font-weight: bold; margin: 4px 0 2px; }
        .exm-api-key .exm-ak-scope { display: block; font-weight: normal; margin: 0 0 3px; }
        .exm-api-key .exm-ak-id { color: #999; font-size: 11px; font-family: monospace; margin-left: 4px; }
        .exm-api-key details.exm-ak-more { margin-top: 8px; }
        .exm-api-key details.exm-ak-more > summary { cursor: pointer; color: #3c8dbc; }
        .exm-api-key details.exm-ak-more[open] > summary { margin-bottom: 8px; }
        .exm-api-key .exm-ak-chips { margin: 6px 0; min-height: 4px; }
        .exm-api-key .exm-ak-chip { display: inline-block; margin: 0 6px 6px 0; padding: 2px 4px 2px 10px; border-radius: 12px; background: #e8f1f8; border: 1px solid #b8d3e8; font-size: 12px; }
        .exm-api-key .exm-ak-chip button { border: 0; background: none; padding: 0 4px; color: #666; }
        .exm-api-key .exm-ak-tables { max-height: 220px; overflow: auto; border: 1px solid #eee; padding: 4px 8px; margin-top: 4px; }
        .exm-api-key .exm-ak-tables label { display: block; font-weight: normal; margin: 0; }
        .exm-api-key .exm-ak-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #ddd; margin: 8px 0 0; padding: 0; list-style: none; }
        .exm-api-key .exm-ak-tabs button { border: 1px solid transparent; border-bottom: 0; background: none; padding: 6px 12px; border-radius: 4px 4px 0 0; color: #3c8dbc; }
        .exm-api-key .exm-ak-tabs button.is-active { border-color: #ddd; background: #fff; color: #333; margin-bottom: -1px; }
        .exm-api-key .exm-ak-tab { display: none; padding: 8px 0 0; }
        .exm-api-key .exm-ak-tab.is-active { display: block; }
        .exm-api-key .exm-ak-code { font-family: monospace; font-size: 12px; }
        .exm-api-key .exm-ak-copyrow { display: flex; gap: 6px; align-items: flex-start; }
        .exm-api-key .exm-ak-copyrow .form-control { flex: 1 1 auto; }
    </style>

    @if (isset($new_key))
        {{-- the key just made: once, with a copy button, and the settings of MCP with the key in them --}}
        <div class="box card box-success exm-ak-new">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-check-circle text-green"></i> {{ exmtrans('api_key.new_key') }}</h3></div>
            <div class="box-body">
                <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> {{ exmtrans('api_key.new_key_warning') }}</div>
                <div class="exm-ak-copyrow">
                    <input type="text" id="exm-ak-new-key" class="form-control exm-ak-code" readonly value="{{ $new_key }}" onfocus="this.select()">
                    <button type="button" class="btn btn-primary exm-ak-copy" data-target="#exm-ak-new-key"><i class="fa fa-copy"></i> {{ exmtrans('api_key.copy') }}</button>
                </div>
                <p class="help-block">{{ exmtrans('api_key.new_key_help') }}</p>
                @if ($mcp['for_new_key'])
                    <p style="margin-top:12px"><strong>{{ exmtrans('api_key.mcp.with_key') }}</strong></p>
                    @include('exment::api_key._mcp_configs', ['configs' => $mcp['configs'], 'prefix' => 'exm-ak-new'])
                @endif
            </div>
        </div>
    @endif
    @if (isset($error))
        <div class="alert alert-danger">{{ $error }}
            @if (isset($error_link)) <a href="{{ $error_link }}">{{ exmtrans('api_key.sign_in_again_link') }}</a>@endif
        </div>
    @endif

    <div class="callout callout-info">
        <p>{{ exmtrans('api_key.mcp_intro') }} <a href="#exm-api-key-mcp">{{ exmtrans('api_key.mcp_intro_link') }}</a></p>
        <p>{{ exmtrans('api_key.help') }}</p>
    </div>

    @if (!$read_only)
    <div class="box card exm-ak-create">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('api_key.create') }}</h3></div>
        <form method="post" action="{{ $action }}" class="form-horizontal" autocomplete="off">
            {{ csrf_field() }}
            {{-- the scopes are the boxes below (a purpose checks its own) --}}
            <input type="hidden" name="scope_type" value="custom">
            <div class="box-body">
                {{-- 1. what the key is for: each presets the scopes --}}
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.purpose') }}</label>
                    <div class="col-sm-10">
                        <div class="exm-ak-purposes">
                        @foreach ($purposes as $key => $p)
                            <label class="exm-ak-purpose {{ $purpose === $key ? 'is-checked' : '' }}">
                                <input type="radio" name="purpose" value="{{ $key }}" data-scopes="{{ json_encode($p['scopes']) }}" {{ $purpose === $key ? 'checked' : '' }}>
                                <i class="fa {{ $p['icon'] }}"></i>
                                <span>
                                    <span class="exm-ak-purpose-title">{{ exmtrans('api_key.purposes.' . $key) }}</span>
                                    <span class="exm-ak-purpose-help">{{ exmtrans('api_key.purposes.' . $key . '_help') }}</span>
                                </span>
                            </label>
                        @endforeach
                        </div>
                        @if (!$mcp['allowed'])
                            <span class="help-block text-warning">{{ exmtrans('api_key.mcp.off') }}</span>
                        @endif
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.name') }}</label>
                    <div class="col-sm-6"><input type="text" name="name" class="form-control" required maxlength="256" value="{{ $old['name'] ?? '' }}" placeholder="{{ exmtrans('api_key.name_placeholder') }}"></div>
                </div>

                {{-- 2. what the key may do, in plain words; the ids are small --}}
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.scopes') }}</label>
                    <div class="col-sm-9">
                        <div class="exm-ak-groups">
                        @foreach ($scope_groups as $group => $groupScopes)
                            <div class="exm-ak-group">
                                <div class="exm-ak-group-title">{{ exmtrans('api_key.scope_groups.' . $group) }}</div>
                                @foreach ($groupScopes as $scope)
                                    <label class="exm-ak-scope"><input type="checkbox" name="scopes[]" value="{{ $scope }}" {{ in_array($scope, $checked_scopes, true) ? 'checked' : '' }}>
                                        {{ exmtrans('api_key.scope_labels.' . $scope) }}<span class="exm-ak-id">{{ $scope }}</span></label>
                                @endforeach
                            </div>
                        @endforeach
                        </div>
                        <details class="exm-ak-more">
                            <summary>{{ exmtrans('api_key.scopes_more') }}</summary>
                            <table class="table table-condensed" style="max-width:760px">
                                <thead><tr><th>{{ exmtrans('api_key.scope_id') }}</th><th>{{ exmtrans('api_key.scope_allows') }}</th><th>{{ exmtrans('api_key.scope_in') }}</th></tr></thead>
                                <tbody>
                                @foreach ($scope_groups as $group => $groupScopes)
                                    @foreach ($groupScopes as $scope)
                                        <tr>
                                            <td><code>{{ $scope }}</code></td>
                                            <td>{{ exmtrans('api_key.scope_labels.' . $scope) }}</td>
                                            <td>{{ implode(' / ', array_map(function ($k) { return exmtrans('api_key.purposes.' . $k); }, array_keys(array_filter($purposes, function ($p) use ($scope) { return in_array($scope, $p['scopes'], true); })))) }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                                </tbody>
                            </table>
                        </details>
                    </div>
                </div>

                {{-- 3. the tables: a search, the chosen ones as chips; the system tables when asked --}}
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('api_key.tables') }}</label>
                    <div class="col-sm-9">
                        <label class="radio-inline"><input type="radio" name="table_type" value="all" {{ ($old['table_type'] ?? 'all') !== 'some' ? 'checked' : '' }}> {{ exmtrans('api_key.all_tables') }}</label>
                        <label class="radio-inline"><input type="radio" name="table_type" value="some" {{ ($old['table_type'] ?? '') === 'some' ? 'checked' : '' }}> {{ exmtrans('api_key.some_tables') }}</label>
                        <div class="exm-ak-table-picker" style="{{ ($old['table_type'] ?? '') === 'some' ? '' : 'display:none' }}">
                            <div class="exm-ak-chips" aria-live="polite"></div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center">
                                <input type="search" class="form-control input-sm exm-ak-table-search" style="max-width:280px" placeholder="{{ exmtrans('api_key.table_search') }}" aria-label="{{ exmtrans('api_key.table_search') }}">
                                <label style="font-weight:normal;margin:0"><input type="checkbox" class="exm-ak-show-system"> {{ exmtrans('api_key.show_system_tables') }}</label>
                            </div>
                            <div class="exm-ak-tables">
                            @foreach ($tables as $table)
                                @php $isChecked = in_array($table['name'], (array)($old['tables'] ?? []), true); @endphp
                                <label class="{{ $table['system'] ? 'exm-ak-system' : '' }}" data-label="{{ mb_strtolower($table['label'] . ' ' . $table['name']) }}" style="{{ $table['system'] && !$isChecked ? 'display:none' : '' }}"><input type="checkbox" name="tables[]" value="{{ $table['name'] }}" data-label="{{ $table['label'] }}" {{ $isChecked ? 'checked' : '' }}> {{ $table['label'] }} <span class="exm-ak-id">{{ $table['name'] }}</span></label>
                            @endforeach
                            </div>
                        </div>
                        <span class="help-block exm-ak-help">{{ exmtrans('api_key.tables_help') }}</span>
                    </div>
                </div>

                {{-- 4. the rest, folded: the end date (90 days), the IP addresses, the certificate --}}
                <div class="form-group">
                    <div class="col-sm-2"></div>
                    <div class="col-sm-9">
                        <details class="exm-ak-more exm-ak-limits" {{ (($old['allowed_ips'] ?? '') !== '' || ($old['client_cert_fingerprint'] ?? '') !== '') ? 'open' : '' }}>
                            <summary>{{ exmtrans('api_key.limits_more', $default_days) }}</summary>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{ exmtrans('api_key.expires_at') }}</label>
                                <div class="col-sm-4">
                                    <select name="days" class="form-control">
                                    @foreach ($days as $d)
                                        <option value="{{ $d }}" {{ (($old['days'] ?? '') !== '' ? intval($old['days']) : $default_days) === $d ? 'selected' : '' }}>{{ exmtrans('api_key.days', $d) }}</option>
                                    @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{ exmtrans('api_key.allowed_ips') }}</label>
                                <div class="col-sm-9">
                                    <textarea name="allowed_ips" rows="3" class="form-control" placeholder="192.0.2.10&#10;198.51.100.0/24&#10;2001:db8::/32">{{ $old['allowed_ips'] ?? '' }}</textarea>
                                    <span class="help-block">{{ exmtrans('api_key.allowed_ips_help') }}</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{ exmtrans('api_key.client_cert') }}</label>
                                <div class="col-sm-9">
                                    <input type="text" name="client_cert_fingerprint" class="form-control" maxlength="128" value="{{ $old['client_cert_fingerprint'] ?? '' }}" placeholder="SHA-256">
                                    <span class="help-block">{{ exmtrans('api_key.client_cert_help') }}</span>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>

                @if ($ask_password)
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('user.current_password') }}</label>
                    <div class="col-sm-6">
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        <span class="help-block">{{ exmtrans('api_key.current_password_help') }}</span>
                    </div>
                </div>
                @endif
            </div>
            <div class="box-footer">
                <div class="col-sm-2"></div>
                <div class="col-sm-9">
                    <button type="submit" class="btn btn-primary">{{ exmtrans('api_key.create_button') }}</button>
                    <span class="text-muted" style="margin-left:10px">{{ exmtrans('api_key.max_keys_help', $max_keys) }}</span>
                </div>
            </div>
        </form>
    </div>
    @endif

    <div class="box card">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('api_key.my_keys') }}</h3></div>
        <div class="box-body">
            @include('exment::api_key._list', ['keys' => $keys, 'admin' => false, 'read_only' => $read_only,
                'revoke_url' => function ($id) use ($action) { return url_join($action, $id, 'revoke'); }])
        </div>
    </div>

    {{-- How to connect AI (MCP), ISS02879: folded; the settings show exk_... in place of a key --}}
    <div class="box card exm-api-key-mcp" id="exm-api-key-mcp">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('api_key.mcp.header') }}</h3></div>
        <div class="box-body">
            @if (!$mcp['allowed'])
                <div class="callout callout-warning"><p>{{ exmtrans('api_key.mcp.off') }}</p></div>
            @endif
            <p>{{ exmtrans('api_key.mcp.help') }}</p>
            <p><strong>{{ exmtrans('api_key.mcp.url') }}</strong> <code>{{ $mcp['url'] }}</code></p>
            <details class="exm-ak-more">
                <summary>{{ exmtrans('api_key.mcp.how_to') }}</summary>
                <p class="text-muted">{{ exmtrans('api_key.mcp.placeholder_help', implode(', ', $mcp['scopes'])) }}</p>
                @include('exment::api_key._mcp_configs', ['configs' => $mcp['placeholder_configs'], 'prefix' => 'exm-ak-how'])
            </details>
        </div>
    </div>

    <script>
    (function () {
        var root = document.currentScript ? document.currentScript.closest('.exm-api-key') : document.querySelector('.exm-api-key');
        if (!root) { return; }
        // a purpose checks its scopes
        function markPurposes() {
            root.querySelectorAll('.exm-ak-purpose').forEach(function (label) {
                label.classList.toggle('is-checked', label.querySelector('input').checked);
            });
        }
        root.querySelectorAll('input[name="purpose"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var scopes = JSON.parse(radio.getAttribute('data-scopes') || '[]');
                root.querySelectorAll('input[name="scopes[]"]').forEach(function (box) {
                    box.checked = scopes.indexOf(box.value) >= 0;
                });
                markPurposes();
            });
        });
        // the tables: show the picker, the chips, the search, the system tables
        var picker = root.querySelector('.exm-ak-table-picker');
        var chips = root.querySelector('.exm-ak-chips');
        var search = root.querySelector('.exm-ak-table-search');
        var showSystem = root.querySelector('.exm-ak-show-system');
        function filterTables() {
            if (!picker) { return; }
            var q = (search.value || '').trim().toLowerCase();
            picker.querySelectorAll('.exm-ak-tables label').forEach(function (label) {
                var box = label.querySelector('input');
                var hit = q === '' || label.getAttribute('data-label').indexOf(q) >= 0;
                var hidden = label.classList.contains('exm-ak-system') && !showSystem.checked && !box.checked;
                label.style.display = hit && !hidden ? '' : 'none';
            });
        }
        function drawChips() {
            if (!chips) { return; }
            chips.innerHTML = '';
            picker.querySelectorAll('input[name="tables[]"]:checked').forEach(function (box) {
                var chip = document.createElement('span');
                chip.className = 'exm-ak-chip';
                chip.textContent = box.getAttribute('data-label');
                var x = document.createElement('button');
                x.type = 'button';
                x.setAttribute('aria-label', '×');
                x.textContent = '×';
                x.addEventListener('click', function () { box.checked = false; drawChips(); filterTables(); });
                chip.appendChild(x);
                chips.appendChild(chip);
            });
        }
        root.querySelectorAll('input[name="table_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (picker) { picker.style.display = root.querySelector('input[name="table_type"]:checked').value === 'some' ? '' : 'none'; }
            });
        });
        if (picker) {
            search.addEventListener('input', filterTables);
            showSystem.addEventListener('change', filterTables);
            picker.querySelectorAll('input[name="tables[]"]').forEach(function (box) { box.addEventListener('change', drawChips); });
            drawChips();
        }
        // the tabs of the settings of MCP
        root.querySelectorAll('.exm-ak-tabs button').forEach(function (button) {
            button.addEventListener('click', function () {
                var set = button.closest('.exm-ak-configs');
                set.querySelectorAll('.exm-ak-tabs button').forEach(function (b) {
                    b.classList.toggle('is-active', b === button);
                    b.setAttribute('aria-selected', b === button ? 'true' : 'false');
                });
                set.querySelectorAll('.exm-ak-tab').forEach(function (tab) {
                    tab.classList.toggle('is-active', '#' + tab.id === button.getAttribute('data-tab'));
                });
            });
        });
        // the copy buttons
        root.querySelectorAll('.exm-ak-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                var field = root.querySelector(button.getAttribute('data-target'));
                if (!field) { return; }
                var done = function () {
                    var text = button.innerHTML;
                    button.innerHTML = '<i class="fa fa-check"></i> ' + {!! json_encode(exmtrans('api_key.copied')) !!};
                    setTimeout(function () { button.innerHTML = text; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value).then(done, function () { field.select(); document.execCommand('copy'); done(); });
                } else {
                    field.select();
                    document.execCommand('copy');
                    done();
                }
            });
        });
    })();
    </script>
</div>

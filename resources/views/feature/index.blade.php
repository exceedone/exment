{{-- Admin setting → Feature management. Data from FeatureController::index. --}}
<style>
.exm-feature table { width: 100%; }
.exm-feature th, .exm-feature td { vertical-align: top !important; }
.exm-feature .exm-feature-help { font-size: .85em; opacity: .75; margin: 2px 0 0; }
.exm-feature .exm-feature-groups { max-height: 140px; overflow: auto; margin-top: 4px; }
.exm-feature .exm-feature-groups label { display: block; font-weight: normal; margin: 0; }
.exm-feature .exm-feature-badge { font-size: .75em; padding: 0 6px; border-radius: 8px; border: 1px solid currentColor; opacity: .8; margin-left: 4px; white-space: nowrap; }
.exm-feature .exm-feature-new { color: #00a65a; opacity: 1; }
.exm-feature .exm-feature-child td:first-child { padding-left: 28px; }
.exm-feature .exm-feature-muted { opacity: .5; }
</style>
<form method="post" action="{{ $action }}" class="exm-feature" id="exm-feature-form">
    {{ csrf_field() }}
    <p>{{ exmtrans('feature.help') }}</p>
    <p class="exm-feature-help">{{ exmtrans('feature.other_settings') }} <a href="{{ $system_url }}">{{ exmtrans('feature.system_link') }}</a></p>
    @if ($read_only)
        <div class="alert alert-warning">{{ exmtrans('feature.read_only') }}</div>
    @endif

    <div class="box card">
        <div class="box-header"><h4>{{ exmtrans('feature.core_header') }}</h4></div>
        <div class="box-body table-responsive">
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('feature.name') }}</th><th style="width:130px">{{ exmtrans('feature.status') }}</th>
                    <th style="width:300px">{{ exmtrans('feature.who') }}</th><th style="width:150px">{{ exmtrans('feature.menu') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($features as $f)
                        @include('exment::feature._row', ['f' => $f, 'role_groups' => $role_groups, 'menu_url' => $menu_url, 'child' => false, 'muted' => false])
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="box card">
        <div class="box-header"><h4>{{ exmtrans('feature.plugin_header') }}</h4></div>
        <div class="box-body table-responsive">
            <p class="exm-feature-help">{{ exmtrans('feature.plugin_help') }} <a href="{{ $role_group_url }}">{{ exmtrans('feature.role_group_link') }}</a></p>
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('feature.name') }}</th><th style="width:130px">{{ exmtrans('feature.status') }}</th>
                    <th style="width:300px">{{ exmtrans('feature.who') }}</th><th style="width:150px">{{ exmtrans('feature.menu') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($plugins as $p)
                        <tr class="{{ $p['enabled'] ? '' : 'exm-feature-muted' }}">
                            <td><strong>{{ $p['label'] }}</strong> <span class="exm-feature-help">{{ $p['name'] }} {{ $p['version'] }}</span>
                                <input type="hidden" name="plugins[{{ $p['name'] }}][present]" value="1">
                                <input type="hidden" name="plugins[{{ $p['name'] }}][shown]" value="{{ json_encode($p['role_groups']) }}"></td>
                            <td>{{ $p['enabled'] ? exmtrans('feature.state_on') : exmtrans('feature.state_plugin_off') }}
                                <p class="exm-feature-help"><a href="{{ $p['edit_url'] }}">{{ exmtrans('feature.plugin_setting_link') }}</a></p></td>
                            <td>
                                @if ($p['everyone'])
                                    {{ exmtrans('feature.who_all') }}<p class="exm-feature-help">{{ $p['everyone_reason'] }}</p>
                                @else
                                    <div class="exm-feature-groups">
                                        @forelse ($role_groups as $g)
                                            <label><input type="checkbox" name="plugins[{{ $p['name'] }}][role_groups][]" value="{{ $g['id'] }}" data-feature-stop="{{ $p['label'] }} / {{ $g['name'] }}" @checked(in_array($g['id'], $p['role_groups'], true))> {{ $g['name'] }}</label>
                                        @empty
                                            <span class="exm-feature-help">{{ exmtrans('feature.no_role_groups') }}</span>
                                        @endforelse
                                    </div>
                                    <p class="exm-feature-help">{{ exmtrans('feature.who_admin_help') }}</p>
                                @endif
                            </td>
                            <td><label style="font-weight:normal"><input type="checkbox" name="plugins[{{ $p['name'] }}][menu]" value="1" @checked($p['menu'])> {{ exmtrans('feature.show') }}</label></td>
                        </tr>
                        @foreach ($p['features'] as $f)
                            @include('exment::feature._row', ['f' => $f, 'role_groups' => $role_groups, 'menu_url' => $menu_url, 'child' => true, 'muted' => !$p['enabled']])
                        @endforeach
                    @empty
                        <tr><td colspan="4" class="exm-feature-help">{{ exmtrans('feature.no_plugins') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (!$read_only)
        <button type="submit" class="btn btn-primary">{{ exmtrans('common.save') }}</button>
    @endif
    {{-- the last field: a form cut on the way (max_input_vars) is not saved --}}
    <input type="hidden" name="complete" value="1">
</form>
<script>
(function () {
    var form = document.getElementById('exm-feature-form');
    if (!form || form.dataset.ready) { return; }
    form.dataset.ready = '1';
    form.addEventListener('change', function (e) {
        if (!e.target.matches('[data-feature-all]')) { return; }
        var row = e.target.closest('[data-feature-row]');
        var groups = row ? row.querySelector('[data-feature-groups]') : null;
        if (groups) { groups.hidden = e.target.value === '1'; }
    });
    // before saving: what stops (a feature turned off, a role group taken off)
    form.addEventListener('submit', function (e) {
        var stops = [];
        form.querySelectorAll('[data-feature-stop]').forEach(function (el) {
            if (el.defaultChecked && !el.checked) { stops.push(el.getAttribute('data-feature-stop')); }
        });
        form.querySelectorAll('[data-feature-all]').forEach(function (el) {
            if (el.value === '0' && el.checked && !el.defaultChecked) { stops.push(el.getAttribute('data-feature-label') + ' / ' + @json(exmtrans('feature.who_groups'))); }
        });
        if (stops.length > 0 && !confirm(@json(exmtrans('feature.confirm_stop')) + '\n\n- ' + stops.join('\n- '))) {
            e.preventDefault();
        }
    });
})();
</script>

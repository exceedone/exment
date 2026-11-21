{{-- Admin setting → Feature management. Data from FeatureController::index. --}}
<style>
.exm-feature table { width: 100%; }
.exm-feature th, .exm-feature td { vertical-align: top !important; }
.exm-feature .exm-feature-help { font-size: .85em; opacity: .75; margin: 2px 0 0; }
.exm-feature .exm-feature-groups { max-height: 140px; overflow: auto; margin-top: 4px; }
.exm-feature .exm-feature-groups label { display: block; font-weight: normal; margin: 0; }
.exm-feature .exm-feature-badge { font-size: .75em; padding: 0 6px; border-radius: 8px; border: 1px solid currentColor; opacity: .75; margin-left: 4px; white-space: nowrap; }
</style>
<form method="post" action="{{ $action }}" class="exm-feature" id="exm-feature-form">
    {{ csrf_field() }}
    <p>{{ exmtrans('feature.help') }}</p>

    <div class="box card">
        <div class="box-header"><h4>{{ exmtrans('feature.core_header') }}</h4></div>
        <div class="box-body table-responsive">
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('feature.name') }}</th><th style="width:110px">{{ exmtrans('feature.enabled') }}</th>
                    <th style="width:280px">{{ exmtrans('feature.who') }}</th><th style="width:110px">{{ exmtrans('feature.menu') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($features as $f)
                        @php $key = $f['key']; $s = $f['setting']; $all = $s['role_groups'] === null; @endphp
                        <tr data-feature-row>
                            <td><strong>{{ $f['definition']['label'] }}</strong>
                                @if (!empty($f['definition']['plugin']))<span class="exm-feature-badge">{{ $f['definition']['plugin'] }}</span>@endif
                                @if ($f['definition']['description'] !== '')<p class="exm-feature-help">{{ $f['definition']['description'] }}</p>@endif
                            </td>
                            <td><label style="font-weight:normal"><input type="checkbox" name="features[{{ $key }}][enabled]" value="1" @checked($s['enabled'])> {{ exmtrans('feature.use') }}</label></td>
                            <td>
                                <label style="font-weight:normal;display:block"><input type="radio" name="features[{{ $key }}][all]" value="1" data-feature-all @checked($all)> {{ exmtrans('feature.who_all') }}</label>
                                <label style="font-weight:normal;display:block"><input type="radio" name="features[{{ $key }}][all]" value="0" data-feature-all @checked(!$all)> {{ exmtrans('feature.who_groups') }}</label>
                                <div class="exm-feature-groups" data-feature-groups @if ($all) hidden @endif>
                                    @forelse ($role_groups as $g)
                                        <label><input type="checkbox" name="features[{{ $key }}][role_groups][]" value="{{ $g['id'] }}" @checked(!$all && in_array($g['id'], $s['role_groups'], true))> {{ $g['name'] }}</label>
                                    @empty
                                        <span class="exm-feature-help">{{ exmtrans('feature.no_role_groups') }}</span>
                                    @endforelse
                                </div>
                                <p class="exm-feature-help">{{ exmtrans('feature.who_admin_help') }}</p>
                            </td>
                            <td>@if (!empty($f['definition']['menu']))<label style="font-weight:normal"><input type="checkbox" name="features[{{ $key }}][menu]" value="1" @checked($s['menu'])> {{ exmtrans('feature.show') }}</label>@else<input type="hidden" name="features[{{ $key }}][menu]" value="1">-@endif</td>
                        </tr>
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
                    <th>{{ exmtrans('feature.name') }}</th><th style="width:110px">{{ exmtrans('feature.enabled') }}</th>
                    <th style="width:280px">{{ exmtrans('feature.who') }}</th><th style="width:110px">{{ exmtrans('feature.menu') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($plugins as $p)
                        <tr>
                            <td><strong>{{ $p['label'] }}</strong> <span class="exm-feature-help">{{ $p['name'] }} {{ $p['version'] }}</span>
                                <input type="hidden" name="plugins[{{ $p['name'] }}][present]" value="1"></td>
                            <td><label style="font-weight:normal"><input type="checkbox" name="plugins[{{ $p['name'] }}][enabled]" value="1" @checked($p['enabled'])> {{ exmtrans('feature.use') }}</label></td>
                            <td>
                                @if ($p['everyone'])
                                    {{ exmtrans('feature.who_all') }}<p class="exm-feature-help">{{ $p['everyone_reason'] }}</p>
                                @else
                                    <div class="exm-feature-groups">
                                        @forelse ($role_groups as $g)
                                            <label><input type="checkbox" name="plugins[{{ $p['name'] }}][role_groups][]" value="{{ $g['id'] }}" @checked(in_array($g['id'], $p['role_groups'], true))> {{ $g['name'] }}</label>
                                        @empty
                                            <span class="exm-feature-help">{{ exmtrans('feature.no_role_groups') }}</span>
                                        @endforelse
                                    </div>
                                    <p class="exm-feature-help">{{ exmtrans('feature.who_admin_help') }}</p>
                                @endif
                            </td>
                            <td><label style="font-weight:normal"><input type="checkbox" name="plugins[{{ $p['name'] }}][menu]" value="1" @checked($p['menu'])> {{ exmtrans('feature.show') }}</label></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="exm-feature-help">{{ exmtrans('feature.no_plugins') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">{{ exmtrans('common.save') }}</button>
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
})();
</script>

{{-- Feature management: the row of a feature (of Exment, or of a plugin under its plugin). --}}
@php
    $key = $f['key'];
    $s = $f['setting'];
    $all = $s['role_groups'] === null;
    $disabled = $f['locked'];
@endphp
<tr data-feature-row class="{{ $child ? 'exm-feature-child' : '' }} {{ $muted ? 'exm-feature-muted' : '' }}">
    <td>@if ($child)<i class="fa fa-level-up fa-rotate-90" aria-hidden="true"></i> @endif<strong>{{ $f['label'] }}</strong>
        @if ($f['new'])<span class="exm-feature-badge exm-feature-new">{{ exmtrans('feature.new') }}</span>@endif
        @if ($f['description'] !== '')<p class="exm-feature-help">{{ $f['description'] }}</p>@endif
        @if (!empty($f['note']))<p class="exm-feature-help">{{ $f['note'] }}</p>@endif
        @if (!empty($f['requires']))<p class="exm-feature-help">{{ exmtrans('feature.requires', ['name' => $f['requires']]) }}</p>@endif
        <input type="hidden" name="features[{{ $key }}][present]" value="1">
    </td>
    <td>
        @if ($disabled)
            {{ exmtrans('feature.state_locked') }}
        @else
            <label style="font-weight:normal"><input type="checkbox" name="features[{{ $key }}][enabled]" value="1" data-feature-stop="{{ $f['label'] }}" data-feature-switch @checked($s['enabled'])> <span data-feature-use-text>{{ exmtrans('feature.use') }}</span></label>
            @if ($f['reason'] === 'requires')<p class="exm-feature-help">{{ exmtrans('feature.state_requires') }}</p>@endif
            @if ($muted)<p class="exm-feature-help">{{ exmtrans('feature.state_plugin_off') }}</p>@endif
        @endif
    </td>
    <td>
        @if (!$f['who'])
            <span class="exm-feature-help">{{ $f['who_text'] ?? exmtrans('feature.who_plugin') }}</span>
            <input type="hidden" name="features[{{ $key }}][all]" value="1">
        @else
            <label style="font-weight:normal;display:block"><input type="radio" name="features[{{ $key }}][all]" value="1" data-feature-all data-feature-label="{{ $f['label'] }}" @checked($all) @disabled($disabled)> {{ exmtrans('feature.who_all') }}</label>
            <label style="font-weight:normal;display:block"><input type="radio" name="features[{{ $key }}][all]" value="0" data-feature-all data-feature-label="{{ $f['label'] }}" @checked(!$all) @disabled($disabled)> {{ exmtrans('feature.who_groups') }}</label>
            <div class="exm-feature-groups" data-feature-groups @if ($all) hidden @endif>
                @forelse ($role_groups as $g)
                    <label><input type="checkbox" name="features[{{ $key }}][role_groups][]" value="{{ $g['id'] }}" data-feature-stop="{{ $f['label'] }} / {{ $g['name'] }}" @checked(!$all && in_array($g['id'], $s['role_groups'], true)) @disabled($disabled)> {{ $g['name'] }}</label>
                @empty
                    <span class="exm-feature-help">{{ exmtrans('feature.no_role_groups') }}</span>
                @endforelse
                <p class="exm-feature-help">{{ exmtrans('feature.who_none_help') }}</p>
            </div>
            <p class="exm-feature-help">{{ $child ? exmtrans('feature.who_child_help') : exmtrans('feature.who_admin_help') }}</p>
        @endif
    </td>
    <td>
        @if ($f['has_menu'])
            <label style="font-weight:normal"><input type="checkbox" name="features[{{ $key }}][menu]" value="1" @checked($s['menu']) @disabled($disabled)> {{ exmtrans('feature.show') }}</label>
            @if (!$f['menu_exists'])<p class="exm-feature-help">{{ exmtrans('feature.menu_missing') }}</p>@endif
        @else
            <input type="hidden" name="features[{{ $key }}][menu]" value="1">
            <span class="exm-feature-help">{{ exmtrans('feature.menu_none') }} <a href="{{ $menu_url }}">{{ exmtrans('feature.menu_link') }}</a></span>
        @endif
    </td>
</tr>

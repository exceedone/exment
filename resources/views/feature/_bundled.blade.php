{{-- Feature management: the free official plugins bundled with Exment (ISS00419). Data from FeatureController::bundledRows. Inside the form of the page: each button posts to its own action. --}}
@if (count($bundled) > 0)
    <div class="box card">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('plugin_distribution.header') }}</h3></div>
        <div class="box-body table-responsive">
            <p class="help-block">{{ exmtrans('plugin_distribution.help') }}</p>
            <ul class="help-block" style="padding-left:20px">
                <li>{{ exmtrans('plugin_distribution.help_plugin_page') }} (<a href="{{ admin_url('plugin') }}">{{ exmtrans('plugin.header') }}</a>)</li>
                <li>{{ exmtrans('plugin_distribution.help_updates') }}</li>
                <li>{{ exmtrans('plugin_distribution.help_upload') }} (<a href="https://github.com/exceedone/exment-plugins-release/tree/main/releases" target="_blank" rel="noopener">{{ exmtrans('plugin_distribution.help_release_link') }}</a>)</li>
            </ul>
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('feature.name') }}</th><th class="exm-feature-col" style="width:170px">{{ exmtrans('plugin_distribution.version') }}</th>
                    <th class="exm-feature-col" style="width:260px">{{ exmtrans('feature.status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($bundled as $b)
                        <tr>
                            <td><strong>{{ $b['label'] }}</strong> <span class="exm-feature-help">{{ $b['name'] }}</span>
                                @if (!empty($b['security']))
                                    <span class="label label-danger" style="margin-left:4px">{{ exmtrans('plugin_distribution.security_badge') }}</span>
                                    <p class="text-danger" style="margin:4px 0 0">{{ exmtrans('plugin_distribution.notice_security_row') }}@if (!empty($b['security_note'])) {{ $b['security_note'] }}@endif</p>
                                @endif
                                @if ($b['description'] !== '')<p class="exm-feature-help">{{ $b['description'] }}</p>@endif
                            </td>
                            <td>{{ $b['version'] }}
                                @if (isset($b['installed_version']) && $b['installed_version'] !== $b['version'])
                                    <p class="exm-feature-help">{{ exmtrans('plugin_distribution.installed_version', ['version' => $b['installed_version']]) }}</p>
                                @endif
                            </td>
                            <td>
                                @switch($b['status'])
                                    @case('not_installed')
                                        <p class="exm-feature-help">{{ exmtrans('plugin_distribution.status_not_installed') }}</p>
                                        @if (!$read_only)
                                            <button type="submit" class="btn btn-sm btn-primary" formaction="{{ $bundled_url }}/{{ $b['uuid'] }}/install" data-feature-confirm="{{ exmtrans('plugin_distribution.confirm_install', ['name' => $b['label']]) }}">{{ exmtrans('plugin_distribution.install') }}</button>
                                        @endif
                                        @break
                                    @case('update')
                                        <p>{{ exmtrans('plugin_distribution.status_update', ['version' => $b['version']]) }}</p>
                                        @if (!$read_only && $bundled_updates)
                                            <button type="submit" class="btn btn-sm btn-default" formaction="{{ $bundled_url }}/{{ $b['uuid'] }}/install" data-feature-confirm="{{ $b['by_hand'] ? exmtrans('plugin_distribution.confirm_update_by_hand', ['name' => $b['label'], 'version' => $b['version'], 'installed' => $b['installed_version']]) : exmtrans('plugin_distribution.confirm_update', ['name' => $b['label'], 'version' => $b['version']]) }}">{{ exmtrans('plugin_distribution.update') }}</button>
                                            @if ($b['by_hand'])<p class="exm-feature-help">{{ exmtrans('plugin_distribution.by_hand_help') }}</p>@endif
                                        @elseif (!$bundled_updates)
                                            <p class="exm-feature-help">{{ exmtrans('plugin_distribution.updates_off') }}</p>
                                        @endif
                                        @break
                                    @case('newer')
                                        <p class="exm-feature-help">{{ exmtrans('plugin_distribution.status_newer') }}</p>
                                        @break
                                    @default
                                        <p class="exm-feature-help">{{ $b['active'] ? exmtrans('plugin_distribution.status_installed') : exmtrans('feature.state_plugin_off') }}</p>
                                @endswitch
                                @if (!empty($b['previous']) && !$read_only && $bundled_updates)
                                    <button type="submit" class="btn btn-sm btn-link {{ $b['previous_unsafe'] ? 'text-danger' : '' }}" formaction="{{ $bundled_url }}/{{ $b['uuid'] }}/rollback" data-feature-confirm="{{ $b['previous_unsafe'] ? exmtrans('plugin_distribution.confirm_rollback_unsafe', ['name' => $b['label'], 'version' => $b['previous']]) : exmtrans('plugin_distribution.confirm_rollback', ['name' => $b['label'], 'version' => $b['previous']]) }}">{{ exmtrans('plugin_distribution.rollback', ['version' => $b['previous']]) }}</button>
                                    @if ($b['previous_unsafe'])<p class="text-danger" style="margin:2px 0 0">{{ exmtrans('plugin_distribution.rollback_unsafe_help', ['version' => $b['previous']]) }}</p>@endif
                                @endif
                                @if (!empty($b['edit_url']))
                                    <p class="exm-feature-help"><a href="{{ $b['edit_url'] }}">{{ exmtrans('feature.plugin_setting_link') }}</a></p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

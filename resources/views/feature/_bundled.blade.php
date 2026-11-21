{{-- Feature management: the free official plugins bundled with Exment (ISS00419). Data from FeatureController::bundledRows. Inside the form of the page: each button posts to its own action. --}}
@if (count($bundled) > 0)
    <div class="box card">
        <div class="box-header"><h4>{{ exmtrans('plugin_distribution.header') }}</h4></div>
        <div class="box-body table-responsive">
            <p class="exm-feature-help">{{ exmtrans('plugin_distribution.help') }}</p>
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('feature.name') }}</th><th style="width:170px">{{ exmtrans('plugin_distribution.version') }}</th>
                    <th style="width:260px">{{ exmtrans('feature.status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($bundled as $b)
                        <tr>
                            <td><strong>{{ $b['label'] }}</strong> <span class="exm-feature-help">{{ $b['name'] }}</span>
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
                                        @if (!$read_only)
                                            <button type="submit" class="btn btn-sm btn-default" formaction="{{ $bundled_url }}/{{ $b['uuid'] }}/install" data-feature-confirm="{{ exmtrans('plugin_distribution.confirm_update', ['name' => $b['label'], 'version' => $b['version']]) }}">{{ exmtrans('plugin_distribution.update') }}</button>
                                        @endif
                                        @break
                                    @case('newer')
                                        <p class="exm-feature-help">{{ exmtrans('plugin_distribution.status_newer') }}</p>
                                        @break
                                    @default
                                        <p class="exm-feature-help">{{ $b['active'] ? exmtrans('plugin_distribution.status_installed') : exmtrans('feature.state_plugin_off') }}</p>
                                @endswitch
                                @if (!empty($b['previous']) && !$read_only)
                                    <button type="submit" class="btn btn-sm btn-link" formaction="{{ $bundled_url }}/{{ $b['uuid'] }}/rollback" data-feature-confirm="{{ exmtrans('plugin_distribution.confirm_rollback', ['name' => $b['label'], 'version' => $b['previous']]) }}">{{ exmtrans('plugin_distribution.rollback', ['version' => $b['previous']]) }}</button>
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

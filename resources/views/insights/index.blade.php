{{-- The page "Insights" (ISS02964). Data from InsightsController::index. The charts are Chart.js
     (vendor/exment/chartjs, loaded on every page); each card links to the log it comes from. --}}
@php
    $labels = array_map(function ($date) { return substr($date, 5); }, $dates);
    $sum = function (array $series) { return array_sum($series); };
    $line = function (array $sets) use ($labels) {
        return json_encode(['type' => 'line', 'labels' => $labels, 'datasets' => array_map(function ($set) {
            return ['label' => $set[0], 'data' => array_values($set[1]), 'color' => $set[2]];
        }, $sets)]);
    };
    $bar = function (array $sets, bool $stacked = false) use ($labels) {
        return json_encode(['type' => 'bar', 'stacked' => $stacked, 'labels' => $labels, 'datasets' => array_map(function ($set) {
            return ['label' => $set[0], 'data' => array_values($set[1]), 'color' => $set[2]];
        }, $sets)]);
    };
    $ranking = function (array $values, string $label, string $color) {
        return json_encode(['type' => 'ranking', 'labels' => array_map('strval', array_keys($values)), 'datasets' => [['label' => $label, 'data' => array_values($values), 'color' => $color]]]);
    };
    // a palette that reads in light and dark, and for colour-blind eyes
    $c = ['blue' => '#3b82f6', 'teal' => '#14b8a6', 'violet' => '#8b5cf6', 'orange' => '#f59e0b', 'red' => '#ef4444', 'gray' => '#94a3b8', 'green' => '#22c55e'];
    $loginFailures = $sum($security['login']['failure']) + $sum($security['login']['lockout']);
    $mcpCalls = $sum($api['mcp_ok']) + $sum($api['mcp_error']);
@endphp
<div class="exm-insights">
    <style>
        .exm-insights .exm-in-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .exm-insights .exm-in-bar form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0; }
        .exm-insights .exm-in-bar input[type=date] { width: auto; }
        .exm-insights .exm-in-spacer { flex: 1 1 auto; }
        .exm-insights .exm-in-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .exm-insights .exm-in-tile { border: 1px solid rgba(0,0,0,.1); border-radius: 6px; padding: 10px 12px; background: var(--bs-body-bg, #fff); }
        .exm-insights .exm-in-tile .exm-in-num { font-size: 24px; font-weight: bold; line-height: 1.2; }
        .exm-insights .exm-in-tile .exm-in-sub { color: #777; font-size: 12px; }
        .exm-insights .exm-in-tile.is-warn .exm-in-num { color: #d97706; }
        .exm-insights .exm-in-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 12px; }
        .exm-insights .exm-in-card { margin: 0; }
        .exm-insights .exm-in-card .box-header { display: flex; align-items: center; gap: 8px; }
        .exm-insights .exm-in-card .box-title { flex: 1 1 auto; }
        .exm-insights .exm-in-card .exm-in-source { font-size: 12px; white-space: nowrap; }
        .exm-insights .exm-in-chart { position: relative; height: 220px; }
        .exm-insights .exm-in-help { color: #777; font-size: 12px; margin: 6px 0 0; }
        .exm-insights table.exm-in-table { width: 100%; font-size: 13px; margin-top: 4px; }
        .exm-insights table.exm-in-table td, .exm-insights table.exm-in-table th { padding: 3px 6px; border-bottom: 1px solid rgba(0,0,0,.06); }
        .exm-insights table.exm-in-table td.num, .exm-insights table.exm-in-table th.num { text-align: right; white-space: nowrap; }
        .exm-insights .exm-in-empty { color: #999; font-size: 13px; padding: 8px 0; }
        .exm-insights h3.exm-in-section { font-size: 16px; margin: 18px 0 8px; font-weight: bold; }
        .exm-insights .exm-in-feature { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; border: 1px solid rgba(0,0,0,.15); color: #888; }
        .exm-insights .exm-in-feature.is-on { border-color: #22c55e; color: #15803d; background: rgba(34,197,94,.08); }
        .exm-insights .exm-in-light { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; background: #94a3b8; }
        .exm-insights .exm-in-light.is-ok { background: #22c55e; }
        .exm-insights .exm-in-light.is-warning { background: #f59e0b; }
        .exm-insights .exm-in-light.is-ng { background: #ef4444; }
        @media (max-width: 560px) { .exm-insights .exm-in-grid { grid-template-columns: 1fr; } }
    </style>

    {{-- the period, the CSV --}}
    <div class="exm-in-bar">
        @foreach ($periods as $p)
            <a href="{{ $url }}?days={{ $p }}" class="btn btn-sm {{ $days === $p || ($days === 0 && $p === \Exceedone\Exment\Services\Insights\InsightReport::DEFAULT_PERIOD && !request()->has('from')) ? 'btn-primary' : 'btn-default' }}">{{ exmtrans('insights.last_days', $p) }}</a>
        @endforeach
        <form method="get" action="{{ $url }}">
            <input type="date" name="from" class="form-control input-sm" value="{{ $report->from->format('Y-m-d') }}" aria-label="{{ exmtrans('insights.from') }}">
            <span>〜</span>
            <input type="date" name="to" class="form-control input-sm" value="{{ $report->to->format('Y-m-d') }}" aria-label="{{ exmtrans('insights.to') }}">
            <button type="submit" class="btn btn-sm btn-default">{{ exmtrans('insights.show') }}</button>
        </form>
        <span class="exm-in-spacer"></span>
        <a href="{{ $csvUrl }}" class="btn btn-sm btn-default" data-nopjax><i class="fa fa-download" aria-hidden="true"></i> CSV</a>
    </div>
    <p class="exm-in-help" style="margin:-4px 0 10px">
        {{ $counted ? exmtrans('insights.counted_until', $counted) : exmtrans('insights.not_counted') }}
        @if (!$perPerson) {{ exmtrans('insights.totals_only') }} @endif
    </p>

    {{-- the tiles --}}
    <div class="exm-in-tiles">
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('insights.active_day') }}</div><div class="exm-in-num">{{ number_format($users['now']['day']) }}</div><div class="exm-in-sub">{{ exmtrans('insights.of_users', number_format($users['total'])) }}</div></div>
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('insights.active_week') }}</div><div class="exm-in-num">{{ number_format($users['now']['week']) }}</div></div>
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('insights.active_month') }}</div><div class="exm-in-num">{{ number_format($users['now']['month']) }}</div></div>
        <div class="exm-in-tile {{ $users['inactive_count'] > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub">{{ exmtrans('insights.inactive', $inactiveDays) }}</div><div class="exm-in-num">{{ number_format($users['inactive_count']) }}</div></div>
        <div class="exm-in-tile {{ $loginFailures > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub">{{ exmtrans('insights.login_failures') }}</div><div class="exm-in-num">{{ number_format($loginFailures) }}</div></div>
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('insights.mcp_calls') }}</div><div class="exm-in-num">{{ number_format($mcpCalls) }}</div></div>
        <div class="exm-in-tile {{ $sum($delivery['mail_failed']) + $sum($delivery['webhook_failed']) > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub">{{ exmtrans('insights.send_failures') }}</div><div class="exm-in-num">{{ number_format($sum($delivery['mail_failed']) + $sum($delivery['webhook_failed'])) }}</div></div>
        <div class="exm-in-tile {{ $sum($health['failed']) > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub">{{ exmtrans('insights.job_failures') }}</div><div class="exm-in-num">{{ number_format($sum($health['failed'])) }}</div></div>
    </div>

    {{-- people and data --}}
    <h3 class="exm-in-section">{{ exmtrans('insights.sections.use') }}</h3>
    <div class="exm-in-grid">
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.active_users') }}</h3><a class="exm-in-source" href="{{ $links['logins'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $line([[exmtrans('insights.active_day'), $users['series']['day'], $c['blue']], [exmtrans('insights.active_week'), $users['series']['week'], $c['teal']], [exmtrans('insights.active_month'), $users['series']['month'], $c['violet']]]) }}" aria-label="{{ exmtrans('insights.cards.active_users') }}" role="img"></canvas></div>
                <p class="exm-in-help">{{ exmtrans('insights.help.active_users') }}</p></div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.tables') }}</h3><a class="exm-in-source" href="{{ $links['audit'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                @if (count($tables['most']) === 0 || $tables['most'][0]['total'] === 0)
                    <div class="exm-in-empty">{{ exmtrans('insights.empty') }}</div>
                @else
                    <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.most_used') }}</th><th class="num">{{ exmtrans('insights.reads') }}</th><th class="num">{{ exmtrans('insights.writes') }}</th><th class="num">API・MCP</th></tr></thead><tbody>
                    @foreach ($tables['most'] as $t)
                        @if ($t['total'] > 0)
                        <tr><td><a href="{{ $links['data'] }}/{{ $t['name'] }}">{{ $t['label'] }}</a></td><td class="num">{{ number_format($t['read']) }}</td><td class="num">{{ number_format($t['write']) }}</td><td class="num">{{ number_format($t['api']) }}</td></tr>
                        @endif
                    @endforeach
                    </tbody></table>
                @endif
                <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('insights.least_used') }}</th><th class="num">{{ exmtrans('insights.uses') }}</th></tr></thead><tbody>
                @foreach ($tables['least'] as $t)
                    <tr><td><a href="{{ $links['data'] }}/{{ $t['name'] }}">{{ $t['label'] }}</a></td><td class="num">{{ number_format($t['total']) }}</td></tr>
                @endforeach
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.views') }}</h3><a class="exm-in-source" href="{{ $links['audit'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                @if (count($tables['views']) === 0)
                    <div class="exm-in-empty">{{ exmtrans('insights.empty') }}</div>
                @else
                    <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.view') }}</th><th>{{ exmtrans('insights.table') }}</th><th class="num">{{ exmtrans('insights.shown') }}</th></tr></thead><tbody>
                    @foreach ($tables['views'] as $v)
                        <tr><td>@if ($v['url'])<a href="{{ $v['url'] }}">{{ $v['label'] }}</a>@else{{ $v['label'] }}@endif</td><td>{{ $v['table'] }}</td><td class="num">{{ number_format($v['count']) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        @if ($perPerson)
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.people') }}</h3><a class="exm-in-source" href="{{ $links['audit'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.person') }}</th><th class="num">{{ exmtrans('insights.uses') }}</th></tr></thead><tbody>
                @forelse ($tables['people'] as $p)
                    <tr><td>{{ $p['name'] }}</td><td class="num">{{ number_format($p['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
                <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('insights.inactive', $inactiveDays) }}</th><th class="num">{{ exmtrans('insights.last_active') }}</th></tr></thead><tbody>
                @forelse ($users['inactive'] as $u)
                    <tr><td>{{ $u['name'] }}</td><td class="num">{{ $u['last'] ?? exmtrans('insights.never') }}</td></tr>
                @empty
                    <tr><td colspan="2" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        @endif
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.growth') }}</h3><a class="exm-in-source" href="{{ $links['tuning'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $line([[exmtrans('insights.rows'), $growth['rows'], $c['blue']]]) }}" aria-label="{{ exmtrans('insights.cards.growth') }}" role="img"></canvas></div>
                <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.table') }}</th><th class="num">{{ exmtrans('insights.rows') }}</th><th class="num">{{ exmtrans('insights.added') }}</th></tr></thead><tbody>
                @forelse ($growth['tables'] as $t)
                    <tr><td>{{ $t['label'] }}</td><td class="num">{{ number_format($t['rows']) }}</td><td class="num">{{ $t['added'] >= 0 ? '+' : '' }}{{ number_format($t['added']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty_night') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.storage') }}</h3><a class="exm-in-source" href="{{ $links['tuning'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ json_encode(['bytes' => true] + json_decode($line([[exmtrans('insights.attachments'), $growth['storage']['attachments'], $c['teal']], [exmtrans('insights.file_server'), $growth['storage']['file_server'], $c['violet']]]), true)) }}" aria-label="{{ exmtrans('insights.cards.storage') }}" role="img"></canvas></div>
                <p class="exm-in-help">{{ exmtrans('insights.help.storage') }}</p></div>
        </div>
    </div>

    {{-- safety --}}
    <h3 class="exm-in-section">{{ exmtrans('insights.sections.safety') }}</h3>
    <div class="exm-in-grid">
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.logins') }}</h3><a class="exm-in-source" href="{{ $links['login_failures'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.login_failure'), $security['login']['failure'], $c['orange']], [exmtrans('insights.login_lockout'), $security['login']['lockout'], $c['red']]], true) }}" aria-label="{{ exmtrans('insights.cards.logins') }}" role="img"></canvas></div>
                <p class="exm-in-help">{{ exmtrans('insights.login_success_total', number_format($sum($security['login']['success']))) }}</p>
                @if ($perPerson && count($security['failures']) > 0)
                    <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.login_name') }}</th><th class="num">{{ exmtrans('insights.failures') }}</th></tr></thead><tbody>
                    @foreach ($security['failures'] as $name => $count)
                        <tr><td>{{ $name }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.changes') }}</h3><a class="exm-in-source" href="{{ $links['operation_logs'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.admin_change'), $security['admin_change'], $c['blue']], [exmtrans('insights.permission_change'), $security['permission_change'], $c['red']]]) }}" aria-label="{{ exmtrans('insights.cards.changes') }}" role="img"></canvas></div>
                @if (count($security['admin_pages']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.page') }}</th><th class="num">{{ exmtrans('insights.changes') }}</th></tr></thead><tbody>
                    @foreach ($security['admin_pages'] as $page => $count)
                        <tr><td><code>{{ $page }}</code></td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
                <p class="exm-in-help"><a href="{{ $links['audit'] }}">{{ exmtrans('insights.permission_history') }}</a></p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.api') }}</h3><a class="exm-in-source" href="{{ $links['api_keys'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.api_key_use'), $api['api_key_use'], $c['blue']], [exmtrans('insights.api_key_refused'), $api['api_key_refused'], $c['red']]]) }}" aria-label="{{ exmtrans('insights.cards.api') }}" role="img"></canvas></div>
                @if (count($api['refusals']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.refused_why') }}</th><th class="num">{{ exmtrans('insights.times') }}</th></tr></thead><tbody>
                    @foreach ($api['refusals'] as $why => $count)
                        <tr><td>{{ exmtrans('api_key.refusals.' . $why) }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
                <p class="exm-in-help">{{ exmtrans('insights.help.api') }}</p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.mcp') }}</h3><a class="exm-in-source" href="{{ $links['mcp_errors'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.ok'), $api['mcp_ok'], $c['teal']], [exmtrans('insights.errors'), $api['mcp_error'], $c['red']]], true) }}" aria-label="{{ exmtrans('insights.cards.mcp') }}" role="img"></canvas></div>
                @if (count($api['tools']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.tool') }}</th><th class="num">{{ exmtrans('insights.times') }}</th></tr></thead><tbody>
                    @foreach ($api['tools'] as $tool => $count)
                        <tr><td><code>{{ $tool }}</code></td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
    </div>

    {{-- the security: what Exment checks and keeps (InsightSecurity); no secret is shown --}}
    <h3 class="exm-in-section">{{ exmtrans('insights.sections.security') }}</h3>
    <div class="exm-in-grid">
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.checks') }}</h3><a class="exm-in-source" href="{{ admin_url('security_check') }}">{{ exmtrans('insights.sec.open_check') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                @forelse ($sec['checks'] as $c)
                    <tr><td style="width:18px"><span class="exm-in-light is-{{ $c['result'] }}" title="{{ exmtrans('insights.sec.light_' . $c['result']) }}"></span></td>
                        <td>{{ $c['label'] }}@if ($c['message'])<div class="exm-in-help" style="margin:0">{{ $c['message'] }}</div>@endif</td>
                        <td class="num">@if ($c['result'] !== 'ok' && $c['url'])<a href="{{ $c['url'] }}">{{ exmtrans('insights.sec.fix') }}</a>@endif</td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.logins') }}</h3><a class="exm-in-source" href="{{ $sec['logins']['url'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td>{{ exmtrans('insights.login_failure') }}</td><td class="num">{{ number_format($sec['logins']['failures']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.login_lockout') }}</td><td class="num" style="{{ $sec['logins']['lockouts'] > 0 ? 'color:#d97706;font-weight:bold' : '' }}">{{ number_format($sec['logins']['lockouts']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.sec.failure_ips') }}</td><td class="num">{{ number_format($sec['logins']['ips']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.sec.new_places') }}</td><td class="num">{{ number_format($sec['logins']['new_places']) }}</td></tr>
                </tbody></table>
                @if ($perPerson && count($sec['logins']['names']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.sec.names_tried') }}</th><th class="num">{{ exmtrans('insights.failures') }}</th></tr></thead><tbody>
                    @foreach ($sec['logins']['names'] as $name => $count)
                        <tr><td>{{ $name }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
                @if ($perPerson && count($sec['logins']['new_place_rows']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.sec.new_places') }}</th><th>{{ exmtrans('insights.person') }}</th><th>IP</th></tr></thead><tbody>
                    @foreach ($sec['logins']['new_place_rows'] as $r)
                        <tr><td>{{ $r['created_at'] ?? '' }}</td><td>{{ $r['user_name'] ?? '' }}</td><td><code>{{ $r['ip'] ?? '' }}</code></td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.admins') }}</h3><a class="exm-in-source" href="{{ $sec['admins']['audit_url'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td><a href="{{ $sec['admins']['url'] }}">{{ exmtrans('insights.sec.admin_count') }}</a></td><td class="num">{{ number_format($sec['admins']['count']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.sec.admin_changes') }}</td><td class="num" style="{{ $sec['admins']['changes'] > 0 ? 'color:#d97706;font-weight:bold' : '' }}">{{ number_format($sec['admins']['changes']) }}</td></tr>
                </tbody></table>
                @if ($perPerson && count($sec['admins']['change_rows']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.sec.when') }}</th><th>{{ exmtrans('insights.sec.by') }}</th><th>{{ exmtrans('insights.sec.role_group') }}</th></tr></thead><tbody>
                    @foreach ($sec['admins']['change_rows'] as $r)
                        <tr><td>{{ $r['created_at'] ?? '' }}</td><td>{{ $r['changed_user'] ?? '' }}</td><td>{{ $r['role_group'] ?? '' }} <span class="exm-in-help">{{ $r['change'] ?? '' }}</span></td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.accounts') }}</h3><a class="exm-in-source" href="{{ $sec['accounts']['url'] }}">{{ exmtrans('insights.sec.settings') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td>{{ exmtrans('insights.sec.two_factor') }}</td><td class="num"><span class="exm-in-light is-{{ $sec['accounts']['two_factor'] ? 'ok' : 'warning' }}"></span> {{ $sec['accounts']['two_factor'] ? exmtrans('insights.sec.on') : exmtrans('insights.sec.off') }}</td></tr>
                    @foreach ($sec['accounts']['mfa'] as $text => $count)
                        <tr><td style="padding-left:18px">{{ $text }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    <tr><td>{{ exmtrans('insights.sec.never_changed') }}</td><td class="num">{{ number_format($sec['accounts']['never_changed']) }} / {{ number_format($sec['accounts']['password_users']) }}</td></tr>
                    @if ($sec['accounts']['expiration_days'] > 0)
                        <tr><td>{{ exmtrans('insights.sec.expired', $sec['accounts']['expiration_days']) }}</td><td class="num" style="{{ $sec['accounts']['expired'] > 0 ? 'color:#d97706;font-weight:bold' : '' }}">{{ number_format($sec['accounts']['expired']) }}</td></tr>
                    @else
                        <tr><td colspan="2" class="exm-in-help">{{ exmtrans('insights.sec.no_expiration') }}</td></tr>
                    @endif
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.api') }}</h3><a class="exm-in-source" href="{{ $sec['api']['url'] }}">{{ exmtrans('insights.sec.settings') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td>{{ exmtrans('insights.sec.keys_active') }}</td><td class="num">{{ number_format($sec['api']['active']) }}</td></tr>
                    <tr><td style="padding-left:18px">{{ exmtrans('insights.sec.keys_long') }}</td><td class="num">{{ number_format($sec['api']['marks']['long_expiry']) }}</td></tr>
                    <tr><td style="padding-left:18px">{{ exmtrans('insights.sec.keys_any_ip') }}</td><td class="num">{{ number_format($sec['api']['marks']['any_ip']) }}</td></tr>
                    <tr><td style="padding-left:18px">{{ exmtrans('insights.sec.keys_unused', \Exceedone\Exment\Services\Insights\InsightSecurity::UNUSED_DAYS) }}</td><td class="num">{{ number_format($sec['api']['marks']['unused']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.api_key_refused') }}</td><td class="num">{{ number_format($sum($api['api_key_refused'])) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.sec.mcp_refused') }}</td><td class="num">{{ number_format($sec['api']['mcp_refused']) }}</td></tr>
                    <tr><td>{{ exmtrans('insights.webhook_failed') }}</td><td class="num">{{ number_format($sum($delivery['webhook_failed'])) }}</td></tr>
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.backup') }}</h3><a class="exm-in-source" href="{{ $sec['backup']['url'] }}">{{ exmtrans('insights.sec.settings') }}</a></div>
            <div class="box-body">
                @php $b = $sec['backup']['last']; @endphp
                <table class="exm-in-table"><tbody>
                    @if ($b)
                        <tr><td>{{ exmtrans('insights.sec.backup_last') }}</td><td class="num"><span class="exm-in-light is-{{ $b['days'] !== null && $b['days'] > 7 ? 'warning' : 'ok' }}"></span> {{ $b['at'] }}</td></tr>
                        <tr><td>{{ exmtrans('insights.sec.backup_db') }}</td><td class="num"><span class="exm-in-light is-{{ $b['database'] === null ? 'warning' : ($b['database'] ? 'ok' : 'ng') }}"></span>
                            {{ $b['database'] === null ? exmtrans('insights.sec.unknown') : ($b['database'] ? exmtrans('insights.sec.yes') : exmtrans('insights.sec.no')) }}</td></tr>
                        @if (!is_null($b['size']))<tr><td>{{ exmtrans('insights.sec.size') }}</td><td class="num" data-size="{{ $b['size'] }}">{{ number_format($b['size'] / 1048576, 1) }} MB</td></tr>@endif
                    @else
                        <tr><td colspan="2"><span class="exm-in-light is-ng"></span> {{ exmtrans('insights.sec.backup_none') }}</td></tr>
                    @endif
                    @if ($sec['backup']['failed'])
                        <tr><td>{{ exmtrans('insights.sec.backup_failed') }}</td><td class="num" style="color:#b91c1c">{{ $sec['backup']['failed']['at'] }}</td></tr>
                        <tr><td colspan="2" class="exm-in-help">{{ $sec['backup']['failed']['message'] }}</td></tr>
                    @endif
                </tbody></table>
                @if ($b && $b['database'] === false)
                    <p class="exm-in-help" style="color:#b91c1c">{{ exmtrans('insights.sec.backup_db_help') }}</p>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.sec.plugins_keys') }}</h3><a class="exm-in-source" href="{{ $sec['plugins']['url'] }}">{{ exmtrans('insights.plugin') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td>{{ exmtrans('insights.sec.manual_plugins') }}</td><td class="num">{{ number_format(count($sec['plugins']['manual'])) }}</td></tr>
                    @foreach (array_slice($sec['plugins']['manual'], 0, 8) as $p)
                        <tr style="{{ $p['active'] ? '' : 'opacity:.55' }}"><td style="padding-left:18px" colspan="2">{{ $p['label'] }}</td></tr>
                    @endforeach
                    <tr><td><a href="{{ $sec['encryption']['url'] }}">{{ exmtrans('insights.sec.data_keys') }}</a></td>
                        <td class="num"><span class="exm-in-light is-{{ !$sec['encryption']['configured'] ? 'warning' : ($sec['encryption']['unavailable'] ? 'ng' : 'ok') }}"></span>
                        {{ !$sec['encryption']['configured'] ? exmtrans('insights.sec.keys_not_set') : exmtrans('insights.sec.keys_count', $sec['encryption']['keys']) }}</td></tr>
                    @if ($sec['encryption']['keys'] > 1)
                        <tr><td colspan="2" class="exm-in-help">{{ exmtrans('insights.sec.rotation') }}</td></tr>
                    @endif
                </tbody></table>
                <p class="exm-in-help">{{ exmtrans('insights.sec.manual_plugins_help') }}</p>
            </div>
        </div>
    </div>

    {{-- how the site runs --}}
    <h3 class="exm-in-section">{{ exmtrans('insights.sections.health') }}</h3>
    <div class="exm-in-grid">
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.delivery') }}</h3><a class="exm-in-source" href="{{ $links['mails'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.mail_sent'), $delivery['mail_sent'], $c['gray']], [exmtrans('insights.mail_failed'), $delivery['mail_failed'], $c['red']], [exmtrans('insights.webhook_failed'), $delivery['webhook_failed'], $c['orange']]]) }}" aria-label="{{ exmtrans('insights.cards.delivery') }}" role="img"></canvas></div>
                @if (count($delivery['webhook_hosts']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.webhook_host') }}</th><th class="num">{{ exmtrans('insights.failures') }}</th></tr></thead><tbody>
                    @foreach ($delivery['webhook_hosts'] as $host => $count)
                        <tr><td>{{ $host }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
                <p class="exm-in-help"><a href="{{ $links['app_log'] }}">{{ exmtrans('insights.app_log') }}</a></p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.browser') }}</h3><a class="exm-in-source" href="{{ $links['browser'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.browser_error'), $browser['error'], $c['red']], [exmtrans('insights.browser_request'), $browser['request'], $c['orange']]], true) }}" aria-label="{{ exmtrans('insights.cards.browser') }}" role="img"></canvas></div>
                @if (count($browser['errors']) > 0)
                    <table class="exm-in-table" style="margin-top:8px"><thead><tr><th>{{ exmtrans('insights.page') }}</th><th class="num">{{ exmtrans('insights.errors') }}</th></tr></thead><tbody>
                    @foreach ($browser['errors'] as $path => $count)
                        <tr><td><code>{{ $path }}</code></td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.slow') }}</h3><a class="exm-in-source" href="{{ $links['browser_pages'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                @if (count($browser['slow']) === 0)
                    <div class="exm-in-empty">{{ exmtrans('insights.empty') }}</div>
                @else
                    <div class="exm-in-chart"><canvas data-insight="{{ $ranking($browser['slow'], exmtrans('insights.times'), $c['orange']) }}" aria-label="{{ exmtrans('insights.cards.slow') }}" role="img"></canvas></div>
                @endif
                <p class="exm-in-help">{{ exmtrans('insights.help.slow', number_format($slowMs / 1000, 0)) }}</p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.jobs') }}</h3><a class="exm-in-source" href="{{ $links['jobs'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body"><div class="exm-in-chart"><canvas data-insight="{{ $bar([[exmtrans('insights.job_success'), $health['success'], $c['gray']], [exmtrans('insights.job_failed'), $health['failed'], $c['red']]], true) }}" aria-label="{{ exmtrans('insights.cards.jobs') }}" role="img"></canvas></div>
                <p class="exm-in-help">
                    {{ exmtrans('insights.queue') }}: <strong>{{ exmtrans('insights.queue_states.' . $health['queue']) }}</strong>
                    @if (!is_null($health['failed_jobs'])) ・ {{ exmtrans('insights.failed_jobs', number_format($health['failed_jobs'])) }} @endif
                </p>
                @if (count($health['jobs']) > 0)
                    <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.job') }}</th><th class="num">{{ exmtrans('insights.failures') }}</th></tr></thead><tbody>
                    @foreach ($health['jobs'] as $job => $count)
                        <tr><td>{{ \Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs::label($job) }}</td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('insights.cards.features') }}</h3><a class="exm-in-source" href="{{ $links['feature'] }}">{{ exmtrans('insights.source') }}</a></div>
            <div class="box-body">
                <p style="margin:0 0 6px">
                @foreach ($features['features'] as $f)
                    <span class="exm-in-feature {{ $f['enabled'] ? 'is-on' : '' }}">@if ($f['enabled'])<i class="fa fa-check" aria-hidden="true"></i> @endif{{ $f['label'] }}</span>
                @endforeach
                </p>
                <table class="exm-in-table"><thead><tr><th><a href="{{ $links['plugin'] }}">{{ exmtrans('insights.plugin') }}</a></th><th class="num">{{ exmtrans('insights.opened') }}</th></tr></thead><tbody>
                @forelse (array_slice($features['plugins'], 0, 10) as $p)
                    <tr style="{{ $p['active'] ? '' : 'opacity:.55' }}"><td>{{ $p['label'] }}</td><td class="num">{{ number_format($p['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                @if (count($features['plugins']) > 10)
                    <tr><td colspan="2" class="exm-in-help"><a href="{{ $links['plugin'] }}">{{ exmtrans('insights.more_plugins', count($features['plugins']) - 10) }}</a></td></tr>
                @endif
                </tbody></table>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var root = document.currentScript ? document.currentScript.closest('.exm-insights') : document.querySelector('.exm-insights');
        if (!root) { return; }
        // Chart.js comes at the end of the page: on the first load, draw when it is there
        if (!window.Chart) {
            var later = function () { draw(); };
            document.addEventListener('DOMContentLoaded', later, { once: true });
            return;
        }
        draw();
        function draw() {
        if (!window.Chart || root.dataset.drawn) { return; }
        root.dataset.drawn = '1';
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark' || document.body.classList.contains('dark-mode');
        var grid = dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.08)';
        var text = dark ? '#cbd5e1' : '#555';
        function bytes(v) {
            var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
            while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
            return (i === 0 ? v : v.toFixed(1)) + ' ' + u[i];
        }
        root.querySelectorAll('canvas[data-insight]').forEach(function (canvas) {
            if (canvas._chart) { canvas._chart.destroy(); }
            var spec = JSON.parse(canvas.getAttribute('data-insight'));
            var ranking = spec.type === 'ranking';
            var datasets = spec.datasets.map(function (d) {
                return {
                    label: d.label, data: d.data, borderColor: d.color, backgroundColor: spec.type === 'line' ? d.color + '22' : d.color,
                    borderWidth: spec.type === 'line' ? 2 : 0, pointRadius: spec.labels.length > 45 ? 0 : 2, tension: 0.25, fill: false, maxBarThickness: 24
                };
            });
            var tick = spec.bytes ? function (v) { return bytes(v); } : undefined;
            canvas._chart = new window.Chart(canvas, {
                type: ranking ? 'bar' : spec.type,
                data: { labels: spec.labels, datasets: datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    indexAxis: ranking ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: datasets.length > 1, labels: { color: text, boxWidth: 12 } },
                        tooltip: spec.bytes ? { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + bytes(ctx.parsed.y); } } } : {}
                    },
                    scales: ranking ? {
                        // the names on the left, the numbers below
                        x: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
                        y: { grid: { display: false }, ticks: { color: text, autoSkip: false } }
                    } : {
                        x: { stacked: !!spec.stacked, grid: { color: grid }, ticks: { color: text, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                        y: Object.assign({ stacked: !!spec.stacked, beginAtZero: true, grid: { color: grid } }, { ticks: tick ? { color: text, precision: 0, callback: tick } : { color: text, precision: 0 } })
                    }
                }
            });
        });
        }
    })();
    </script>
</div>

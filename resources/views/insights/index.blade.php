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
    @include('exment::insights._styles')

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

    @include('exment::insights._charts')
</div>

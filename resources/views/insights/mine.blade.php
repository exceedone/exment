{{-- "My insights" (ISS02964): the numbers of the user who looks, only. Data from MyInsightsController::index. --}}
@php
    $labels = array_map(function ($date) { return substr($date, 5); }, $dates);
    $c = ['blue' => '#3b82f6', 'teal' => '#14b8a6', 'violet' => '#8b5cf6', 'orange' => '#f59e0b', 'red' => '#ef4444', 'gray' => '#94a3b8'];
    $hourLabels = array_map(function ($h) { return sprintf('%d', $h); }, range(0, 23));
@endphp
<div class="exm-insights exm-my-insights">
    @include('exment::insights._styles')

    <div class="exm-in-bar">
        @foreach ($periods as $p)
            <a href="{{ $url }}?days={{ $p }}" class="btn btn-sm {{ $days === $p || ($days === 0 && $p === \Exceedone\Exment\Services\Insights\InsightReport::DEFAULT_PERIOD && !request()->has('from')) ? 'btn-primary' : 'btn-default' }}">{{ exmtrans('insights.last_days', $p) }}</a>
        @endforeach
        <form method="get" action="{{ $url }}">
            <input type="date" name="from" class="form-control input-sm" value="{{ $from->format('Y-m-d') }}" aria-label="{{ exmtrans('insights.from') }}">
            <span>〜</span>
            <input type="date" name="to" class="form-control input-sm" value="{{ $to->format('Y-m-d') }}" aria-label="{{ exmtrans('insights.to') }}">
            <button type="submit" class="btn btn-sm btn-default">{{ exmtrans('insights.show') }}</button>
        </form>
    </div>
    <p class="exm-in-help" style="margin:-4px 0 10px"><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('my_insights.only_you') }}</p>

    <div class="exm-in-tiles">
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('my_insights.days_in') }}</div><div class="exm-in-num">{{ $logins['days_in'] }}</div><div class="exm-in-sub">{{ exmtrans('my_insights.of_days', count($dates)) }}</div></div>
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('my_insights.created') }}</div><div class="exm-in-num">{{ number_format($data['created']) }}</div></div>
        <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('my_insights.updated') }}</div><div class="exm-in-num">{{ number_format($data['updated']) }}</div></div>
        @if (isset($workflow))
            <div class="exm-in-tile {{ $workflow['waiting'] > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub"><a href="{{ $workflow['url'] }}">{{ exmtrans('my_insights.waiting') }}</a></div><div class="exm-in-num">{{ number_format($workflow['waiting']) }}</div></div>
            <div class="exm-in-tile"><div class="exm-in-sub">{{ exmtrans('my_insights.requests') }}</div><div class="exm-in-num">{{ number_format($workflow['requests']) }}</div></div>
        @endif
        <div class="exm-in-tile {{ count($logins['failures']) > 0 || count($logins['new_places']) > 0 ? 'is-warn' : '' }}"><div class="exm-in-sub">{{ exmtrans('my_insights.attention') }}</div><div class="exm-in-num">{{ count($logins['failures']) + count($logins['new_places']) }}</div><div class="exm-in-sub">{{ exmtrans('my_insights.attention_help') }}</div></div>
    </div>

    <div class="exm-in-grid">
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.logins') }}</h3></div>
            <div class="box-body">
                <div class="exm-in-chart" style="height:150px"><canvas data-insight="{{ json_encode(['type' => 'bar', 'labels' => $labels, 'datasets' => [['label' => exmtrans('my_insights.logins'), 'data' => array_values($logins['days']), 'color' => $c['blue']]]]) }}" role="img" aria-label="{{ exmtrans('my_insights.cards.logins') }}"></canvas></div>
                <div class="exm-in-chart" style="height:150px;margin-top:8px"><canvas data-insight="{{ json_encode(['type' => 'bar', 'labels' => $hourLabels, 'datasets' => [['label' => exmtrans('my_insights.by_hour'), 'data' => $logins['hours'], 'color' => $c['teal']]]]) }}" role="img" aria-label="{{ exmtrans('my_insights.by_hour') }}"></canvas></div>
                <p class="exm-in-help">{{ exmtrans('my_insights.by_hour_help') }}</p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.safety') }}</h3></div>
            <div class="box-body">
                <table class="exm-in-table"><thead><tr><th>{{ exmtrans('my_insights.new_places', $newPlaceDays) }}</th><th>IP</th><th>{{ exmtrans('my_insights.browser') }}</th></tr></thead><tbody>
                @forelse ($logins['new_places'] as $row)
                    <tr><td>{{ $row['at'] }}</td><td><code>{{ $row['ip'] }}</code></td><td>{{ $row['agent'] }}</td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
                <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('my_insights.failures') }}</th><th>{{ exmtrans('my_insights.result') }}</th><th>IP</th></tr></thead><tbody>
                @forelse ($logins['failures'] as $row)
                    <tr><td>{{ $row['at'] }}</td><td>{{ $row['result'] === 'lockout' ? exmtrans('insights.login_lockout') : exmtrans('insights.login_failure') }}</td><td><code>{{ $row['ip'] }}</code></td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
                @if (is_array($sessions))
                    <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('my_insights.sessions') }}</th><th>IP</th><th>{{ exmtrans('my_insights.browser') }}</th></tr></thead><tbody>
                    @forelse ($sessions as $row)
                        <tr><td>{{ $row['last'] }}@if ($row['current']) <span class="exm-in-help">{{ exmtrans('my_insights.this_browser') }}</span>@endif</td><td><code>{{ $row['ip'] }}</code></td><td>{{ $row['agent'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                    @endforelse
                    </tbody></table>
                @endif
                <p class="exm-in-help">{{ exmtrans('my_insights.safety_help') }}</p>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.data') }}</h3></div>
            <div class="box-body">
                <table class="exm-in-table"><thead><tr><th>{{ exmtrans('my_insights.tables_i_read') }}</th><th class="num">{{ exmtrans('insights.reads') }}</th></tr></thead><tbody>
                @forelse ($data['tables'] as $t)
                    <tr><td><a href="{{ admin_urls('data', $t['name']) }}">{{ $t['label'] }}</a></td><td class="num">{{ number_format($t['read']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
                <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('my_insights.views_i_open') }}</th><th>{{ exmtrans('insights.table') }}</th><th class="num">{{ exmtrans('insights.shown') }}</th></tr></thead><tbody>
                @forelse ($data['views'] as $v)
                    <tr><td><a href="{{ $v['url'] }}">{{ $v['label'] }}</a></td><td>{{ $v['table'] }}</td><td class="num">{{ number_format($v['count']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ $counted ? exmtrans('insights.empty') : exmtrans('insights.empty_night') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.written') }}</h3></div>
            <div class="box-body">
                <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.table') }}</th><th class="num">{{ exmtrans('my_insights.created') }}</th><th class="num">{{ exmtrans('my_insights.updated') }}</th></tr></thead><tbody>
                @forelse ($data['written'] as $t)
                    <tr><td><a href="{{ admin_urls('data', $t['name']) }}">{{ $t['label'] }}</a></td><td class="num">{{ number_format($t['create']) }}</td><td class="num">{{ number_format($t['update']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        {{-- the cards of the plugins (as ExmentPM) and of other code: MyInsights::extraCards --}}
        @foreach ($cards as $card)
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ $card['title'] }}</h3>@if ($card['url'])<a class="exm-in-source" href="{{ $card['url'] }}">{{ $card['url_label'] ?? exmtrans('my_insights.open_list') }}</a>@endif</div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                @foreach ($card['rows'] as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num" style="{{ $row['warn'] ? 'color:#d97706;font-weight:bold' : '' }}">{{ $row['value'] }}</td></tr>
                @endforeach
                </tbody></table>
                @if ($card['help'])<p class="exm-in-help">{{ $card['help'] }}</p>@endif
            </div>
        </div>
        @endforeach
        @if (isset($files))
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.files') }}</h3><a class="exm-in-source" href="{{ $files['url'] }}">{{ exmtrans('my_insights.open_list') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><tbody>
                    <tr><td>{{ exmtrans('my_insights.files_opened') }}</td><td class="num">{{ number_format($files['opened']) }}</td></tr>
                    <tr><td>{{ exmtrans('my_insights.files_uploaded') }}</td><td class="num">{{ number_format($files['uploaded']) }}</td></tr>
                    <tr><td>{{ exmtrans('my_insights.files_shared') }}</td><td class="num">{{ number_format($files['shared']) }}</td></tr>
                    <tr><td>{{ exmtrans('my_insights.files_links') }}</td><td class="num">{{ number_format($files['links']) }}</td></tr>
                </tbody></table>
                <table class="exm-in-table" style="margin-top:10px"><thead><tr><th>{{ exmtrans('my_insights.files_top') }}</th><th class="num">{{ exmtrans('insights.times') }}</th></tr></thead><tbody>
                @forelse ($files['top'] as $f)
                    <tr><td><a href="{{ $f['url'] }}">{{ $f['name'] }}</a></td><td class="num">{{ number_format($f['count']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="exm-in-empty">{{ exmtrans('insights.empty') }}</td></tr>
                @endforelse
                </tbody></table>
            </div>
        </div>
        @endif
        <div class="box card exm-in-card">
            <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('my_insights.cards.api') }}</h3><a class="exm-in-source" href="{{ $api['url'] }}">{{ exmtrans('api_key.button') }}</a></div>
            <div class="box-body">
                <table class="exm-in-table"><thead><tr><th>{{ exmtrans('api_key.name') }}</th><th class="num">{{ exmtrans('insights.times') }}</th><th>{{ exmtrans('my_insights.last_used') }}</th><th>{{ exmtrans('api_key.expires_at') }}</th></tr></thead><tbody>
                @forelse ($api['keys'] as $k)
                    <tr><td>{{ $k['name'] }}@foreach ($k['marks'] as $mark) <span class="exm-in-mark">{{ exmtrans('my_insights.key_marks.' . $mark) }}</span>@endforeach @if ($k['refused'])<div class="exm-in-help" style="color:#d97706">{{ exmtrans('api_key.last_refused') }}: {{ $k['refused'] }}</div>@endif</td><td class="num">{{ number_format($k['uses']) }}</td><td>{{ $k['last_used'] ?? '-' }}</td><td>{{ $k['expires'] }}</td></tr>
                @empty
                    <tr><td colspan="4" class="exm-in-empty">{{ exmtrans('api_key.no_keys') }}</td></tr>
                @endforelse
                </tbody></table>
                <p style="margin:10px 0 4px">{{ exmtrans('my_insights.mcp', number_format($api['mcp']['ok']), number_format($api['mcp']['error'])) }}</p>
                @if (count($api['tools']) > 0)
                    <table class="exm-in-table"><thead><tr><th>{{ exmtrans('insights.tool') }}</th><th class="num">{{ exmtrans('insights.times') }}</th></tr></thead><tbody>
                    @foreach ($api['tools'] as $tool => $count)
                        <tr><td><code>{{ $tool }}</code></td><td class="num">{{ number_format($count) }}</td></tr>
                    @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>
    </div>

    @include('exment::insights._charts')
</div>

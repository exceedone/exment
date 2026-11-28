{{-- The data archive (ISS02970): DataArchiveController --}}
@php
    $mb = function ($bytes) { return number_format(max(0, intval($bytes)) / 1048576, 1) . ' MB'; };
    $tabUrl = function ($tab) use ($url) { return $url . '?tab=' . $tab; };
    $statusClass = ['writing' => 'label-default', 'verified' => 'label-info', 'completed' => 'label-success', 'restored' => 'label-primary', 'failed' => 'label-danger'];
@endphp
<p>
    <a href="{{ admin_url('log_management') }}" class="btn btn-default btn-sm"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ exmtrans('log_management.header') }}</a>
</p>

@if(!$ready)
<div class="callout callout-warning"><p>{{ exmtrans('data_archive.not_ready') }}</p></div>
@else
<ul class="nav nav-tabs" id="data-archive-tabs" style="margin-bottom:12px">
    @foreach(['sources', 'archives', 'trash', 'events'] as $t)
    <li class="nav-item {{ $tab === $t ? 'active' : '' }}"><a href="{{ $tabUrl($t) }}" data-tab="{{ $t }}" class="nav-link {{ $tab === $t ? 'active' : '' }}" @if($tab === $t) aria-current="page" @endif>{{ exmtrans('data_archive.tabs.' . $t) }}</a></li>
    @endforeach
</ul>

@if($tab === 'sources')
    @if(isset($preview))
    <div class="box box-primary" id="data-archive-preview" data-source="{{ $preview['source'] }}">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('data_archive.preview_header', ['source' => exmtrans('data_archive.sources.' . $preview['source'])]) }}</h3></div>
        <div class="box-body">
        @if(isset($preview['error']))
            <p class="text-danger">{{ $preview['error'] }}</p>
        @elseif($preview['count'] === 0)
            <p>{{ exmtrans('data_archive.preview_none', ['before' => substr($preview['before'], 0, 10)]) }}</p>
        @else
            <p>{{ exmtrans('data_archive.preview_text', ['count' => number_format($preview['count']), 'before' => substr($preview['before'], 0, 10), 'from' => substr(strval($preview['from']), 0, 10), 'to' => substr(strval($preview['to']), 0, 10), 'mb' => $mb($preview['bytes']), 'zip' => $mb($preview['compressed'])]) }}</p>
            <div class="table-responsive" style="max-height:240px;overflow:auto">
            <table class="table table-condensed">
                <thead><tr><th>{{ exmtrans('data_archive.month') }}</th><th class="text-right">{{ exmtrans('data_archive.rows') }}</th></tr></thead>
                <tbody>
                @foreach($preview['months'] as $month => $n)
                    <tr><td>{{ $month }}</td><td class="text-right">{{ number_format($n) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <p class="small text-muted">{{ exmtrans('data_archive.preview_help', ['driver' => exmtrans('data_archive.drivers.' . $driver)]) }}</p>
            @if($isSystem)
            <form method="post" action="{{ admin_urls('data_archive', 'run') }}" id="data-archive-run"
                onsubmit="return confirm({{ json_encode(exmtrans('data_archive.confirm_run', ['count' => number_format($preview['count']), 'mb' => $mb($preview['bytes'])])) }});">
                {{ csrf_field() }}
                <input type="hidden" name="source" value="{{ $preview['source'] }}">
                <input type="hidden" name="before" value="{{ substr($preview['before'], 0, 10) }}">
                <input type="hidden" name="expected" value="{{ $preview['count'] }}">
                <button type="submit" class="btn btn-warning"><i class="fa fa-archive" aria-hidden="true"></i> {{ exmtrans('data_archive.run') }}</button>
            </form>
            @endif
        @endif
        </div>
    </div>
    @endif

    <div class="box box-default" id="data-archive-sources">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('data_archive.tabs.sources') }}</h3></div>
        <div class="box-body table-responsive">
        <form method="post" action="{{ admin_urls('data_archive', 'policies') }}" id="data-archive-policies">
            {{ csrf_field() }}
            <table class="table table-condensed">
                <thead><tr>
                    <th>{{ exmtrans('data_archive.source') }}</th>
                    <th class="text-right">{{ exmtrans('data_archive.rows') }}</th>
                    <th>{{ exmtrans('data_archive.oldest') }}</th>
                    <th>{{ exmtrans('data_archive.older_days') }}</th>
                    <th>{{ exmtrans('data_archive.trash_days') }}</th>
                    <th>{{ exmtrans('data_archive.estimate') }}</th>
                </tr></thead>
                <tbody>
                @foreach($sources as $key => $s)
                    <tr data-source="{{ $key }}">
                        <td>{{ exmtrans('data_archive.sources.' . $key) }}<div class="small text-muted">{{ $s['table'] ?? exmtrans('data_archive.no_table') }}</div></td>
                        <td class="text-right">{{ is_null($s['rows']) ? '-' : number_format($s['rows']) }}</td>
                        <td>{{ $s['oldest'] ?? '-' }}</td>
                        <td><input type="number" min="0" max="36500" class="form-control input-sm" style="width:90px" name="policies[{{ $key }}][older_days]" value="{{ $s['policy']['older_days'] ?: '' }}" placeholder="-" @if(!$isSystem) disabled @endif aria-label="{{ exmtrans('data_archive.older_days') }}"></td>
                        <td><input type="number" min="1" max="36500" class="form-control input-sm" style="width:90px" name="policies[{{ $key }}][trash_days]" value="{{ $s['policy']['trash_days'] }}" @if(!$isSystem) disabled @endif aria-label="{{ exmtrans('data_archive.trash_days') }}"></td>
                        <td style="white-space:nowrap">
                            @if(isset($s['table']))
                            <input type="date" class="form-control input-sm" style="display:inline-block;width:150px" form="data-archive-estimate-{{ $key }}" name="before" value="{{ \Carbon\Carbon::today()->subDays($s['policy']['older_days'] ?: 365)->format('Y-m-d') }}" aria-label="{{ exmtrans('data_archive.before') }}">
                            <button type="submit" class="btn btn-default btn-sm" form="data-archive-estimate-{{ $key }}">{{ exmtrans('data_archive.estimate') }}</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="small text-muted">{{ exmtrans('data_archive.policy_help', ['days' => $trashDays, 'notice' => $noticeDays]) }}</p>
            @if($isSystem)
            <div class="form-inline">
                <label>{{ exmtrans('data_archive.driver') }}</label>
                <select name="driver" class="form-control input-sm">
                    @foreach(\Exceedone\Exment\Services\DataArchive\ArchiveStore::DRIVERS as $d)
                    <option value="{{ $d }}" @if($driver === $d) selected @endif>{{ exmtrans('data_archive.drivers.' . $d) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-sm">{{ trans('admin.save') }}</button>
            </div>
            @endif
        </form>
        @foreach($sources as $key => $s)
            <form method="get" action="{{ $url }}" id="data-archive-estimate-{{ $key }}" style="display:none">
                <input type="hidden" name="tab" value="sources"><input type="hidden" name="preview" value="{{ $key }}">
            </form>
        @endforeach
        </div>
    </div>
@endif

@if($tab === 'archives' || $tab === 'trash')
    @if(isset($restore))
    <div class="box box-primary" id="data-archive-restore" data-archive="{{ $restore['archive']->id }}">
        <div class="box-header with-border"><h3 class="box-title">{{ exmtrans('data_archive.restore_header', ['id' => $restore['archive']->id]) }}</h3></div>
        <div class="box-body">
        @if(isset($restore['error']))
            <p class="text-danger">{{ $restore['error'] }}</p>
        @else
            @php $rp = $restore['preview']; @endphp
            <p>{{ exmtrans('data_archive.restore_text', ['version' => $rp['version'] ?? '-', 'at' => $rp['written_at'] ?? '-', 'count' => number_format($rp['count']), 'insert' => number_format($rp['insert']), 'existing' => number_format($rp['existing'])]) }}</p>
            @if(count($rp['missing_columns']) > 0)
                <p class="text-danger" id="data-archive-restore-refused">{{ exmtrans('data_archive.errors.columns', ['columns' => implode(', ', $rp['missing_columns'])]) }}</p>
            @elseif(!$rp['table'])
                <p class="text-danger">{{ exmtrans('data_archive.errors.source') }}</p>
            @elseif($isSystem && $rp['insert'] > 0)
            <form method="post" action="{{ admin_urls('data_archive', $restore['archive']->id, 'restore') }}"
                onsubmit="return confirm({{ json_encode(exmtrans('data_archive.confirm_restore', ['count' => number_format($rp['insert'])])) }});">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary"><i class="fa fa-undo" aria-hidden="true"></i> {{ exmtrans('data_archive.restore') }}</button>
            </form>
            @endif
        @endif
        </div>
    </div>
    @endif

    <div class="box box-default" id="data-archive-list">
        <div class="box-body table-responsive">
        @if($tab === 'trash')<p class="small text-muted">{{ exmtrans('data_archive.trash_help', ['notice' => $noticeDays]) }}</p>@endif
        <table class="table table-condensed table-striped">
            <thead><tr>
                <th>#</th>
                <th>{{ exmtrans('data_archive.source') }}</th>
                <th>{{ exmtrans('data_archive.status') }}</th>
                <th class="text-right">{{ exmtrans('data_archive.rows') }}</th>
                <th class="text-right">{{ exmtrans('data_archive.size') }}</th>
                <th>{{ exmtrans('data_archive.range') }}</th>
                <th>{{ exmtrans('data_archive.made') }}</th>
                @if($tab === 'trash')<th>{{ exmtrans('data_archive.purge_at') }}</th>@endif
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($archives as $a)
                <tr data-archive="{{ $a->id }}">
                    <td>{{ $a->id }}</td>
                    <td>{{ exmtrans('data_archive.sources.' . $a->source) }}<div class="small text-muted">{{ exmtrans('data_archive.drivers.' . $a->disk) }}</div></td>
                    <td>
                        <span class="label {{ $statusClass[$a->status] ?? 'label-default' }}">{{ exmtrans('data_archive.statuses.' . $a->status) }}</span>
                        @if($a->warning)<span class="label label-warning">{{ exmtrans('data_archive.warning') }}</span>@endif
                        @if($a->legal_hold)<span class="label label-danger" data-hold="1"><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('data_archive.legal_hold') }}</span>@endif
                        @if($a->message)<div class="small text-muted" style="max-width:280px;word-break:break-all">{{ $a->message }}</div>@endif
                    </td>
                    <td class="text-right">{{ number_format($a->row_count) }}@if($a->restored_count > 0)<div class="small text-muted">{{ exmtrans('data_archive.restored_count', ['count' => number_format($a->restored_count)]) }}</div>@endif</td>
                    <td class="text-right">{{ $mb($a->bytes) }}</td>
                    <td class="small">{{ optional($a->range_from)->format('Y-m-d') }} ～ {{ optional($a->range_to)->format('Y-m-d') }}</td>
                    <td class="small">{{ optional($a->created_at)->format('Y-m-d H:i') }}<br>{{ $userName($a->created_user_id) }}</td>
                    @if($tab === 'trash')<td class="small">{{ $a->legal_hold ? exmtrans('data_archive.held') : optional($a->purge_at)->format('Y-m-d') }}</td>@endif
                    <td style="white-space:nowrap">
                    @if($isSystem)
                        @php $act = admin_urls('data_archive', $a->id, 'action'); @endphp
                        @if($tab === 'archives' && in_array($a->status, ['completed', 'restored'], true))
                            <a href="{{ $url }}?tab=archives&restore={{ $a->id }}" class="btn btn-default btn-xs">{{ exmtrans('data_archive.restore') }}</a>
                        @endif
                        <form method="post" action="{{ $act }}" style="display:inline">{{ csrf_field() }}
                            <input type="hidden" name="do" value="{{ $a->legal_hold ? 'unhold' : 'hold' }}">
                            <button type="submit" class="btn btn-default btn-xs">{{ exmtrans($a->legal_hold ? 'data_archive.unhold' : 'data_archive.hold') }}</button>
                        </form>
                        @if($tab === 'archives' && !$a->legal_hold)
                        <form method="post" action="{{ $act }}" style="display:inline" onsubmit="return confirm({{ json_encode(exmtrans('data_archive.confirm_trash', ['days' => \Exceedone\Exment\Services\DataArchive\DataArchiver::policy($a->source)['trash_days']])) }});">{{ csrf_field() }}
                            <input type="hidden" name="do" value="trash">
                            <button type="submit" class="btn btn-danger btn-xs">{{ exmtrans('data_archive.trash') }}</button>
                        </form>
                        @endif
                        @if($tab === 'trash')
                        <form method="post" action="{{ $act }}" style="display:inline">{{ csrf_field() }}
                            <input type="hidden" name="do" value="untrash">
                            <button type="submit" class="btn btn-default btn-xs">{{ exmtrans('data_archive.untrash') }}</button>
                        </form>
                        @endif
                    @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-muted">{{ exmtrans('data_archive.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
@endif

@if($tab === 'events')
    <div class="box box-default" id="data-archive-events">
        <div class="box-body table-responsive">
        <form method="get" action="{{ $url }}" class="form-inline" style="margin-bottom:8px">
            <input type="hidden" name="tab" value="events">
            <select name="source" class="form-control input-sm" aria-label="{{ exmtrans('data_archive.source') }}">
                <option value="">{{ exmtrans('data_archive.all_sources') }}</option>
                @foreach($sourceKeys as $k)<option value="{{ $k }}" @if($filter['source'] === $k) selected @endif>{{ exmtrans('data_archive.sources.' . $k) }}</option>@endforeach
            </select>
            <select name="action" class="form-control input-sm" aria-label="{{ exmtrans('data_archive.action') }}">
                <option value="">{{ exmtrans('data_archive.all_actions') }}</option>
                @foreach(['preview', 'archive', 'verify', 'delete_rows', 'restore', 'trash', 'untrash', 'hold', 'unhold', 'purge_notice', 'purge', 'policy', 'failed'] as $k)<option value="{{ $k }}" @if($filter['action'] === $k) selected @endif>{{ exmtrans('data_archive.actions.' . $k) }}</option>@endforeach
            </select>
            <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search" aria-hidden="true"></i> {{ trans('admin.filter') }}</button>
        </form>
        <table class="table table-condensed table-striped">
            <thead><tr>
                <th>{{ exmtrans('log_management.column.datetime') }}</th>
                <th>{{ exmtrans('data_archive.action') }}</th>
                <th>{{ exmtrans('data_archive.source') }}</th>
                <th>#</th>
                <th class="text-right">{{ exmtrans('data_archive.rows') }}</th>
                <th>{{ exmtrans('data_archive.who') }}</th>
                <th>{{ exmtrans('data_archive.detail') }}</th>
            </tr></thead>
            <tbody>
            @forelse($events as $e)
                <tr data-event="{{ $e->action }}">
                    <td class="small" style="white-space:nowrap">{{ optional($e->created_at)->format('Y-m-d H:i:s') }}</td>
                    <td>{{ exmtrans('data_archive.actions.' . $e->action) }}</td>
                    <td>{{ $e->source ? exmtrans('data_archive.sources.' . $e->source) : '-' }}</td>
                    <td>{{ $e->data_archive_id ?? '-' }}</td>
                    <td class="text-right">{{ is_null($e->rows) ? '' : number_format($e->rows) }}</td>
                    <td>{{ $userName($e->user_id) }}</td>
                    <td class="small" style="word-break:break-all">{{ $e->detail }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">{{ exmtrans('data_archive.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
@endif
@endif

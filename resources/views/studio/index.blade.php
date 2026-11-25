{{-- Studio, phase 1: the screens of the site on one page. Data from Services\Studio\ScreenList. --}}
<div class="exm-studio">
    <div class="exm-studio-search mb-3">
        <input type="search" class="form-control exm-studio-filter" placeholder="{{ exmtrans('studio.filter_placeholder') }}" aria-label="{{ exmtrans('studio.filter_placeholder') }}">
    </div>

    <div class="exm-studio-heading-row">
        <h4 class="exm-studio-heading">{{ exmtrans('studio.tables') }}</h4>
        <span class="exm-studio-heading-tools">
            <a href="{{ $app_url }}" class="btn btn-sm btn-default" target="_blank" rel="noopener" title="{{ exmtrans('studio.app_link_help') }}"><i class="fa fa-mobile" aria-hidden="true"></i> {{ exmtrans('studio.app_link') }}</a>
            @if ($create_url)
                <a href="{{ $create_url }}" class="btn btn-sm btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.create_button') }}</a>
            @endif
        </span>
    </div>
    @if (count($tables) === 0)
        <p class="exm-studio-empty">{{ exmtrans('studio.no_tables') }}</p>
    @else
    <div class="box card exm-studio-panel">
        <div class="box-body table-responsive p-0">
            <table class="table exm-studio-table">
                <thead>
                    <tr>
                        <th>{{ exmtrans('studio.table') }}</th>
                        <th>{{ exmtrans('studio.screen_name') }}</th>
                        <th>{{ exmtrans('studio.screen_type') }}</th>
                        <th class="exm-studio-actions-head">{{ exmtrans('studio.actions') }}</th>
                    </tr>
                </thead>
                @foreach ($tables as $table)
                    @php
                        $screens = [];
                        foreach ($table['forms'] ?? [] as $form) {
                            $screens[] = ['name' => $form['name'], 'type' => exmtrans('studio.type_form'), 'icon' => 'fa-pencil-square-o', 'default' => $form['default'], 'edit_url' => $form['edit_url'], 'setting_url' => $form['setting_url'] ?? null, 'open_url' => null, 'copy_url' => $form['copy_url']];
                        }
                        foreach ($table['views'] ?? [] as $view) {
                            $screens[] = ['name' => $view['name'], 'type' => $view['kind'], 'icon' => 'fa-th-list', 'default' => $view['default'], 'edit_url' => $view['edit_url'], 'setting_url' => null, 'open_url' => $view['open_url'], 'copy_url' => $view['copy_url']];
                        }
                        $search = mb_strtolower($table['table_view_name'] . ' ' . $table['table_name'] . ' ' . implode(' ', array_column($screens, 'name')));
                        $span = max(1, count($screens));
                    @endphp
                    <tbody class="exm-studio-group" data-studio-name="{{ $search }}">
                        @for ($i = 0; $i < $span; $i++)
                            <tr>
                                @if ($i === 0)
                                    <td rowspan="{{ $span }}" class="exm-studio-table-cell" @if (!empty($table['color'])) style="border-left-color: {{ $table['color'] }}" @endif>
                                        <div class="exm-studio-table-name">
                                            <i class="fa {{ $table['icon'] }}" aria-hidden="true"></i>
                                            @if ($table['data_url'])
                                                <a href="{{ $table['data_url'] }}">{{ $table['table_view_name'] }}</a>
                                            @else
                                                {{ $table['table_view_name'] }}
                                            @endif
                                        </div>
                                        <div class="exm-studio-table-links">
                                            @if ($table['form_create_url'])
                                                <a href="{{ $table['form_create_url'] }}"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.type_form') }}</a>
                                            @endif
                                            @if ($table['view_create_url'])
                                                <a href="{{ $table['view_create_url'] }}"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.type_view') }}</a>
                                            @endif
                                            @if ($table['column_url'])
                                                <a href="{{ $table['column_url'] }}"><i class="fa fa-list" aria-hidden="true"></i> {{ exmtrans('studio.columns') }}</a>
                                            @endif
                                            @if ($table['table_url'])
                                                <a href="{{ $table['table_url'] }}"><i class="fa fa-cog" aria-hidden="true"></i> {{ exmtrans('studio.table_setting') }}</a>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                                @if (isset($screens[$i]))
                                    @php $screen = $screens[$i]; @endphp
                                    <td>
                                        <a href="{{ $screen['edit_url'] }}">{{ $screen['name'] }}</a>
                                        @if ($screen['default'])<span class="exm-studio-badge">{{ exmtrans('studio.default') }}</span>@endif
                                    </td>
                                    <td class="exm-studio-kind"><i class="fa {{ $screen['icon'] }}" aria-hidden="true"></i> {{ $screen['type'] }}</td>
                                    <td class="exm-studio-actions">
                                        @if ($screen['open_url'])
                                            <a href="{{ $screen['open_url'] }}" title="{{ exmtrans('studio.open') }}"><i class="fa fa-external-link" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.open') }}</span></a>
                                        @endif
                                        <a href="{{ $screen['edit_url'] }}" title="{{ exmtrans('studio.edit') }}"><i class="fa fa-pencil" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.edit') }}</span></a>
                                        @if ($screen['setting_url'])
                                            <a href="{{ $screen['setting_url'] }}" title="{{ exmtrans('studio.form_setting') }}"><i class="fa fa-cog" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.form_setting') }}</span></a>
                                        @endif
                                        @if ($screen['copy_url'])
                                            <a href="{{ $screen['copy_url'] }}" title="{{ exmtrans('studio.copy') }}"><i class="fa fa-copy" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.copy') }}</span></a>
                                        @endif
                                    </td>
                                @else
                                    <td colspan="3" class="exm-studio-empty">{{ exmtrans('studio.none') }}</td>
                                @endif
                            </tr>
                        @endfor
                    </tbody>
                @endforeach
            </table>
        </div>
    </div>
    @endif
    <p class="exm-studio-empty exm-studio-nomatch" hidden>{{ exmtrans('studio.no_match') }}</p>

    <div class="exm-studio-row">
        <div class="box card exm-studio-panel">
            <div class="box-header with-border"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('studio.dashboards') }}</div>
            <div class="box-body">
                @if (count($dashboards) === 0)
                    <p class="exm-studio-empty">{{ exmtrans('studio.none') }}</p>
                @else
                    <ul class="exm-studio-items">
                        @foreach ($dashboards as $dashboard)
                            <li>
                                <a href="{{ $dashboard['open_url'] }}">{{ $dashboard['name'] }}</a>
                                <span class="exm-studio-kind">{{ $dashboard['system'] ? exmtrans('studio.dashboard_system') : exmtrans('studio.dashboard_user') }}</span>
                                @if ($dashboard['default'])<span class="exm-studio-badge">{{ exmtrans('studio.default') }}</span>@endif
                                @if ($dashboard['edit_url'])
                                    <a href="{{ $dashboard['edit_url'] }}" class="exm-studio-copy" title="{{ exmtrans('studio.edit') }}"><i class="fa fa-pencil" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.edit') }}</span></a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        @if (count($plugin_pages) > 0)
            <div class="box card exm-studio-panel">
                <div class="box-header with-border"><i class="fa fa-plug" aria-hidden="true"></i> {{ exmtrans('studio.plugin_pages') }}</div>
                <div class="box-body">
                    <ul class="exm-studio-items">
                        @foreach ($plugin_pages as $page)
                            <li>
                                <a href="{{ $page['open_url'] }}">{{ $page['name'] }}</a>
                                <a href="{{ $page['edit_url'] }}" class="exm-studio-copy" title="{{ exmtrans('studio.edit') }}"><i class="fa fa-cog" aria-hidden="true"></i><span class="sr-only">{{ exmtrans('studio.edit') }}</span></a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>

    <div class="box card exm-studio-panel exm-studio-diagram-panel">
        <div class="box-header with-border"><i class="fa fa-sitemap" aria-hidden="true"></i> {{ exmtrans('studio.diagram') }}</div>
        <div class="box-body">
            @if (count($diagram['nodes']) === 0)
                <p class="exm-studio-empty">{{ exmtrans('studio.no_relation') }}</p>
            @else
                <div class="exm-studio-legend">
                    <span><svg width="28" height="10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" class="exm-studio-edge exm-studio-edge-one_to_many"/></svg> {{ exmtrans('studio.relation_one_to_many') }}</span>
                    <span><svg width="28" height="10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" class="exm-studio-edge exm-studio-edge-many_to_many"/></svg> {{ exmtrans('studio.relation_many_to_many') }}</span>
                    <span><svg width="28" height="10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" class="exm-studio-edge exm-studio-edge-select_table"/></svg> {{ exmtrans('studio.relation_select_table') }}</span>
                </div>
                <div class="exm-studio-diagram">
                    <svg width="{{ $diagram['width'] }}" height="{{ $diagram['height'] }}" viewBox="0 0 {{ $diagram['width'] }} {{ $diagram['height'] }}" role="img" aria-label="{{ exmtrans('studio.diagram') }}">
                        <defs>
                            <marker id="exm-studio-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                <path d="M0,0 L10,5 L0,10 z" class="exm-studio-arrow"/>
                            </marker>
                        </defs>
                        @foreach ($diagram['edges'] as $edge)
                            <path d="{{ $edge['d'] }}" class="exm-studio-edge exm-studio-edge-{{ $edge['type'] }}" marker-end="url(#exm-studio-arrow)" @if ($edge['type'] === 'many_to_many') marker-start="url(#exm-studio-arrow)" @endif>
                                @if ($edge['label'] !== '')<title>{{ $edge['label'] }}</title>@endif
                            </path>
                        @endforeach
                        @foreach ($diagram['nodes'] as $node)
                            <g class="exm-studio-node">
                                <rect x="{{ $node['x'] }}" y="{{ $node['y'] }}" width="{{ $diagram_box['width'] }}" height="{{ $diagram_box['height'] }}" rx="4"/>
                                <text x="{{ $node['x'] + intdiv($diagram_box['width'], 2) }}" y="{{ $node['y'] + intdiv($diagram_box['height'], 2) }}" text-anchor="middle" dominant-baseline="central">{{ \Illuminate\Support\Str::limit($node['name'], 22) }}<title>{{ $node['name'] }}</title></text>
                            </g>
                        @endforeach
                    </svg>
                </div>
            @endif
            @if (count($diagram['others']) > 0)
                <p class="exm-studio-others">{{ exmtrans('studio.no_relation_tables') }}: {{ implode('、', $diagram['others']) }}</p>
            @endif
        </div>
    </div>
</div>

<style>
.exm-studio [hidden] { display: none !important; }
.exm-studio-heading-row { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: .5rem; }
.exm-studio-heading { font-size: 1.05rem; margin: 0; }
.exm-studio-table { margin: 0; }
.exm-studio-table > thead th { white-space: nowrap; }
.exm-studio-table > tbody + tbody { border-top: 2px solid #d2d6de; }
.exm-studio-table td { vertical-align: middle; }
.exm-studio-table-cell { vertical-align: top !important; border-left: 3px solid transparent; min-width: 200px; }
.exm-studio-table-name { font-weight: 600; overflow-wrap: anywhere; }
.exm-studio-table-links { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: .8rem; margin-top: 4px; }
.exm-studio-table-links a { opacity: .8; }
.exm-studio-actions-head { width: 1%; }
.exm-studio-actions { white-space: nowrap; }
.exm-studio-actions a { color: inherit; opacity: .6; margin-right: 10px; }
.exm-studio-actions a:hover { opacity: 1; }
.exm-studio-items { list-style: none; padding: 0; margin: 0; }
.exm-studio-items li { padding: 3px 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.exm-studio-kind { font-size: .85rem; opacity: .75; white-space: nowrap; }
.exm-studio-badge { font-size: .7rem; padding: 0 6px; border-radius: 8px; border: 1px solid currentColor; opacity: .7; }
.exm-studio-copy { margin-left: auto; opacity: .6; color: inherit; }
.exm-studio-copy + .exm-studio-copy { margin-left: 0; }
.exm-studio-copy:hover { opacity: 1; }
.exm-studio-empty { opacity: .7; margin: 0; }
.exm-studio-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; margin-bottom: 16px; }
.exm-studio-row .exm-studio-panel { margin: 0; }
.exm-studio-diagram { overflow-x: auto; }
.exm-studio-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: .8rem; margin-bottom: 8px; opacity: .8; }
.exm-studio-node rect { fill: #fff; stroke: #3c8dbc; }
.exm-studio-node text { font-size: 12px; fill: currentColor; }
.exm-studio-edge { fill: none; stroke: #7a8793; stroke-width: 1.5; }
.exm-studio-edge-many_to_many { stroke: #3c8dbc; }
.exm-studio-edge-select_table { stroke-dasharray: 4 3; }
.exm-studio-arrow { fill: #7a8793; }
.exm-studio-others { font-size: .85rem; opacity: .75; margin: 8px 0 0; }
</style>

<script>
(function () {
    var input = document.querySelector('.exm-studio-filter');
    if (!input) { return; }
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        document.querySelectorAll('.exm-studio-group').forEach(function (card) {
            var hit = q === '' || (card.getAttribute('data-studio-name') || '').indexOf(q) !== -1;
            card.hidden = !hit;
            if (hit) { shown++; }
        });
        var none = document.querySelector('.exm-studio-nomatch');
        if (none) { none.hidden = shown !== 0; }
    });
})();
</script>

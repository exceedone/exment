{{--
    Chart box toolbar: [フィルター ▾] (the box's own chart filter, popover), the box menu [⋯]
    (sort by a field, 集計方法 ◀ / 表示件数 ◀ / 表示方法 ◀ submenus, 値ラベル, Excel export — the table and
    Excel's own chart, made by DashboardBoxController::excel) and the runtime chart-type switcher. Handlers and styles: public/vendor/exment/js/dashboard.js, css/dashboard.css.
    The selection is kept by the JS per box and sent as bf_* / ct / cs / cd / tn on the box request; the
    re-rendered body echoes it back, so state survives every reload. ct / cs / cd / tn are also remembered
    per user (DashboardBoxController::chartState); the chart filter (bf_*) is page-lifetime.

    $summary       ChartItem::summaryMenu(): the 集計 switch {choices, configured, current, column}
                   (null = none). Sent as `ca`, page-lifetime like bf_*: never remembered
    $show_as       ChartItem::showAsMenu(): the 表示方法 {choices, current, column} (null = none).
                   Sent as `sv`, page-lifetime like `ca`
    $top_n         ChartItem::topNMenu(): the 表示件数 {choices, current, label, applied, other,
                   other_hint, column, hint} (null = none). Sent as `tn`, remembered like `cs`
    $title         ChartItem::boxTitle(): the box title while 集計方法 / 表示方法 / 表示件数 draws
                   the measure another way (null = the configured title)
    $types         type => label of the switchable types ([] = no switcher)
    $current_type  the rendered type
    $configured_type  the box's own type: picking it on the switcher drops the remembered choice
    $second        ChartItem::secondPreview(): the 2つ目の値 {name, values, type, right} the 複合 type's
                   preview draws (null = none)
    $menu              whether the box menu renders at all
    $sort_fields       [{key, label}] the fields the chart can be sorted by ([] = no sort section)
    $sort_field        key of the applied sort's field (null = the view's order)
    $sort_desc         direction of the applied sort
    $labels_available  the type can show its values on the points
    $display_labels    値ラベル applied
    $colors_reset      the box has hand-painted colors the viewer may reset (色をリセット)
    $fields        ChartFilter::fields() ([] = no chart filter)
    $filter_count  active chart-filter columns (badge)
    $captions      [{label, value}] of the active chart filters (caption under the toolbar)
--}}
<div class="exment-chart-toolbar" @if(!empty($title)) data-ca-title="{{ $title }}" data-ca-reset-label="{{ exmtrans('dashboard.chart_summary.reset') }}" @endif>
    <span class="ct-spacer"></span>
    @if(count($fields))
    <div class="ct-item">
        <button type="button" class="ct-btn" data-ct-pop>
            <span class="ct-lbl">{{ exmtrans('dashboard.chart_filter.label') }}</span>
            @if($filter_count > 0)<span class="ct-cnt">{{ $filter_count }}</span>@endif
            <span class="ct-car">▾</span>
        </button>
        {{-- wide enough for the fields to sit side by side (one ~190px column each); the JS shrinks it to the card when needed --}}
        <div class="ct-pop" style="min-width: {{ min(190 * max(2, count($fields)) + 30, 720) }}px">
            <h4>{{ exmtrans('dashboard.dashboard_box_options.chart_filters') }}</h4>
            <div class="ct-fgrid">
                @foreach($fields as $field)
                {{-- a capped list is dimmed only while nothing is ticked on it: ticked values stay listed to be unticked --}}
                @php($capped = !empty($field['capped']) && !$field['active'])
                <div class="ct-fitem{{ $capped ? ' dis' : '' }}" @if($capped) title="{{ exmtrans('dashboard.filter_bar.narrow_first') }}" @endif>
                    <span>{{ $field['label'] }}@if($field['active'])<em class="ct-fsel">{{ $field['style'] === 'range' ? 1 : count($field['selected']) }}</em>@endif</span>
                    @if($field['style'] === 'range')
                    @php($inputType = $field['kind'] === 'number' ? 'number' : ($field['kind'] === 'text' ? 'text' : 'date'))
                    {{-- an input shows its typed bound, else the data's end (data-end); left at its end it filters nothing (dashboard.js collectChartFilter) --}}
                    @php($min = $field['range']['min'] ?? '')
                    @php($max = $field['range']['max'] ?? '')
                    <div class="ct-frange">
                        <input type="{{ $inputType }}" class="exment-bf-range{{ $field['range']['from'] !== '' ? ' active' : '' }}" data-column="{{ $field['column'] }}" data-bound="from" data-end="{{ $min }}" value="{{ $field['range']['from'] !== '' ? $field['range']['from'] : $min }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_from') }}">
                        <i>–</i>
                        <input type="{{ $inputType }}" class="exment-bf-range{{ $field['range']['to'] !== '' ? ' active' : '' }}" data-column="{{ $field['column'] }}" data-bound="to" data-end="{{ $max }}" value="{{ $field['range']['to'] !== '' ? $field['range']['to'] : $max }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_to') }}">
                    </div>
                    @else
                    <div class="exment-bf-list{{ $field['active'] ? ' active' : '' }}" data-column="{{ $field['column'] }}">
                        @if(!empty($field['capped']))<small>{{ exmtrans('dashboard.filter_bar.narrow_first') }}</small>@endif
                        @if(count($field['options']) > 8)<input type="search" class="exment-bf-search" placeholder="{{ trans('admin.search') }}" autocomplete="off">@endif
                        <div class="exment-bf-opts">
                            @foreach($field['options'] as $option)
                            @php($on = in_array((string) $option['id'], $field['selected'], true))
                            <label class="{{ $on ? 'on' : '' }}"><input type="checkbox" class="exment-bf-check" value="{{ $option['id'] }}" {{ $on ? 'checked' : '' }}><span>{{ $option['name'] }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            <div class="ct-pfoot"><a class="exment-bf-reset">{{ trans('admin.reset') }}</a></div>
        </div>
    </div>
    @endif
    @if(count($types))
    {{-- chart type picker: a thumbnail per switchable type (type_icon.blade); a click sets the
         hidden select, whose change event switches the box as before. The panel below the
         thumbnails previews the box's data in the hovered type, the current one otherwise, as
         Excel's Change Chart Type dialog does — the chart itself stays (dashboard.js renderPreview) --}}
    {{-- a tile names its type short (積み上げ棒グラフ（複数系列） → 積み上げ棒); its title keeps the full name --}}
    @php($shortLabel = function ($label) { return preg_replace(['/\s*[（(][^（()）]*[)）]\s*$/u', '/\s*(グラフ|チャート|chart)$/iu'], '', (string) $label); })
    <div class="ct-item ctp-item">
        <button type="button" class="ct-btn ctp-btn" data-ct-pop title="{{ exmtrans('dashboard.dashboard_box_options.chart_type') }}">
            @include('exment::dashboard.chart.type_icon', ['type' => $current_type])
            <span class="ctp-cur">{{ $shortLabel($types[$current_type] ?? $current_type) }}</span><span class="ct-car">▾</span>
        </button>
        <div class="ct-pop ctp-pop{{ count($types) > 8 ? ' ctp-pop-wide' : '' }}" data-palette="{{ json_encode($palette) }}"@if(!empty($second)) data-second="{{ json_encode($second) }}"@endif>
            <h4>{{ exmtrans('dashboard.dashboard_box_options.chart_type') }}</h4>
            <div class="ctp-hint">{{ exmtrans('dashboard.chart_type_pick.hint') }}</div>
            <div class="ctp-grid{{ count($types) > 8 ? ' ctp-wide' : '' }}">
                @foreach($types as $type => $label)
                <button type="button" class="ctp-tile{{ $type === $current_type ? ' on' : '' }}" data-ct-type="{{ $type }}" title="{{ $label }}">
                    @include('exment::dashboard.chart.type_icon', ['type' => $type])
                    <span>{{ $shortLabel($label) }}</span>
                </button>
                @endforeach
            </div>
            <div class="ctp-prev">
                <div class="ctp-prev-name"></div>
                <div class="ctp-prev-chart"></div>
            </div>
        </div>
        <select class="exment-ct-switch" data-configured="{{ $configured_type }}" hidden>
            @foreach($types as $type => $label)
            <option value="{{ $type }}" {{ $type === $current_type ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @endif
    @if($menu)
    <div class="ct-item">
        {{-- the box menu (Power BI's "…"): sort by a field, display options, export; monochrome, colored only while open --}}
        @php($summary_switched = !empty($summary) && $summary['current'] !== $summary['configured'])
        <button type="button" class="ct-btn ct-ico" data-cs-pop title="{{ exmtrans('dashboard.chart_menu.label') }}"><i class="fa fa-ellipsis-h"></i></button>
        <div class="ct-pop cs-pop">
            @if(!empty($sort_fields))
            <h4>{{ exmtrans('dashboard.chart_sort.by') }}</h4>
            <ul class="cs-list">
                @foreach($sort_fields as $field)
                <li class="cs-row{{ $field['key'] === $sort_field ? ' on' : '' }}" data-cs-field="{{ $field['key'] }}"><i class="fa fa-check"></i><span>{{ $field['label'] }}</span></li>
                @endforeach
            </ul>
            <ul class="cs-list cs-sep">
                <li class="cs-row{{ $sort_field && $sort_desc ? ' on' : '' }}" data-cs-dir="desc"><i class="fa fa-check"></i><i class="fa fa-sort-amount-desc"></i><span>{{ exmtrans('dashboard.chart_sort.desc') }}</span></li>
                <li class="cs-row{{ $sort_field && !$sort_desc ? ' on' : '' }}" data-cs-dir="asc"><i class="fa fa-check"></i><i class="fa fa-sort-amount-asc"></i><span>{{ exmtrans('dashboard.chart_sort.asc') }}</span></li>
            </ul>
            @endif
            @if(!empty($summary))
            {{-- 集計方法 ◀: the measure drawn by another summary type for this screen only — the
                 server reruns the view's query with it (ChartSummarySwitch). One row opening a
                 submenu, as Excel's 値の集計方法 ▸. data-ca-applied echoes the condition drawn
                 when it is not the view's ('' otherwise): the JS mirrors it --}}
            <ul class="cs-list{{ empty($sort_fields) ? '' : ' cs-sep' }}" data-ca-configured="{{ $summary['configured'] }}" data-ca-applied="{{ $summary_switched ? $summary['current'] : '' }}">
                <li class="cs-row ca-row{{ $summary_switched ? ' ca-on' : '' }}" data-ca-open>
                    <i class="fa fa-calculator"></i><span>{{ exmtrans('dashboard.chart_summary.menu') }}</span>
                    <b class="ca-cur">{{ $summary['choices'][$summary['current']] ?? '' }}</b><i class="fa fa-caret-left ca-arrow"></i>
                    <div class="ca-fly"><div class="ca-fly-in">
                        <h4>{{ exmtrans('dashboard.chart_summary.heading', $summary['column']) }}</h4>
                        <ul class="cs-list">
                            @foreach($summary['choices'] as $condition => $label)
                            <li class="cs-row{{ (string) $condition === $summary['current'] ? ' on' : '' }}" data-ca-pick="{{ $condition }}"><i class="fa fa-check"></i><span>{{ $label }}</span>@if((string) $condition === $summary['configured'])<em class="ca-badge">{{ exmtrans('dashboard.chart_summary.view_setting') }}</em>@endif</li>
                            @endforeach
                        </ul>
                        <div class="ca-hint">{{ exmtrans('dashboard.chart_summary.hint') }}</div>
                    </div></div>
                </li>
            </ul>
            @endif
            @if(!empty($top_n))
            {{-- 表示件数 ◀: the N largest points only, the rest grouped into その他 (ChartTopN) —
                 opens like the 集計方法 row; a pick applies at once. Remembered per user like the
                 sort. data-tn-applied echoes what is drawn ('' = every point); the checkbox says
                 whether a pick groups the rest (disabled where the values do not add up) --}}
            {{-- one group with the 集計方法 row, under it --}}
            <ul class="cs-list{{ empty($sort_fields) || !empty($summary) ? '' : ' cs-sep' }}" data-tn-applied="{{ $top_n['applied'] }}">
                <li class="cs-row ca-row tn-row{{ $top_n['current'] !== '' ? ' ca-on' : '' }}" data-ca-open>
                    <i class="fa fa-sort-amount-desc"></i><span>{{ exmtrans('dashboard.chart_top_n.menu') }}</span>
                    <b class="ca-cur">{{ $top_n['label'] }}</b><i class="fa fa-caret-left ca-arrow"></i>
                    <div class="ca-fly"><div class="ca-fly-in">
                        <h4>{{ exmtrans('dashboard.chart_top_n.heading', $top_n['column']) }}</h4>
                        <ul class="cs-list">
                            @foreach($top_n['choices'] as $n => $label)
                            <li class="cs-row{{ (string) $n === $top_n['current'] ? ' on' : '' }}" data-tn-pick="{{ $n }}"><i class="fa fa-check"></i><span>{{ $label }}</span></li>
                            @endforeach
                        </ul>
                        <ul class="cs-list cs-sep">
                            <li class="cs-row tn-other{{ $top_n['other'] ? ' on' : '' }}{{ $top_n['other_hint'] ? ' dis' : '' }}" data-tn-other{!! $top_n['other_hint'] ? ' aria-disabled="true"' : '' !!}><i class="fa tn-box"></i><span>{{ exmtrans('dashboard.chart_top_n.group_rest') }}</span></li>
                        </ul>
                        <div class="ca-hint">{{ $top_n['hint'] }}@if($top_n['other_hint'])<br>{{ $top_n['other_hint'] }}@endif</div>
                    </div></div>
                </li>
            </ul>
            @endif
            @if(!empty($show_as))
            {{-- 表示方法 ◀: the drawn values shown as a share of the total, a running total, a
                 difference from the previous item, for this screen only (ChartShowAs), as Excel's
                 値の表示方法 ▸ — opens like the 集計方法 row. data-sv-applied echoes the way the
                 values are shown ('' = as they are): the JS mirrors it --}}
            @php($show_as_on = $show_as['current'] !== \Exceedone\Exment\Services\Dashboard\ChartShowAs::NONE)
            {{-- one group with the 集計方法 / 表示件数 rows, under them --}}
            <ul class="cs-list{{ empty($sort_fields) || !empty($summary) || !empty($top_n) ? '' : ' cs-sep' }}" data-sv-applied="{{ $show_as_on ? $show_as['current'] : '' }}">
                <li class="cs-row ca-row sv-row{{ $show_as_on ? ' ca-on' : '' }}" data-ca-open>
                    <i class="fa fa-percent"></i><span>{{ exmtrans('dashboard.chart_show_as.menu') }}</span>
                    <b class="ca-cur">{{ $show_as['choices'][$show_as['current']] ?? '' }}</b><i class="fa fa-caret-left ca-arrow"></i>
                    <div class="ca-fly"><div class="ca-fly-in">
                        <h4>{{ exmtrans('dashboard.chart_show_as.heading', $show_as['column']) }}</h4>
                        <ul class="cs-list">
                            @foreach($show_as['choices'] as $choice => $label)
                            <li class="cs-row{{ $choice === $show_as['current'] ? ' on' : '' }}" data-sv-pick="{{ $choice }}"><i class="fa fa-check"></i><span>{{ $label }}</span></li>
                            @endforeach
                        </ul>
                        <div class="ca-hint">{{ exmtrans('dashboard.chart_summary.hint') }}</div>
                    </div></div>
                </li>
            </ul>
            @endif
            @if($labels_available)
            <h4 class="cs-sec">{{ exmtrans('dashboard.chart_menu.display') }}</h4>
            <ul class="cs-list">
                <li class="cs-row{{ $display_labels ? ' on' : '' }}" data-cd-toggle="labels"><i class="fa fa-check"></i><span>{{ exmtrans('dashboard.chart_menu.labels') }}</span></li>
            </ul>
            @endif
            <ul class="cs-list cs-sep">
                <li class="cs-row" data-cx-export><i class="fa fa-file-excel-o"></i><span>{{ exmtrans('dashboard.chart_menu.export_excel') }}</span></li>
                @if(!empty($colors_reset))<li class="cs-row" data-cc-reset><i class="fa fa-paint-brush"></i><span>{{ exmtrans('dashboard.chart_color.reset') }}</span></li>@endif
            </ul>
            <div class="ct-pfoot"><a class="exment-cs-reset">{{ trans('admin.reset') }}</a></div>
        </div>
    </div>
    @endif
</div>
@if(count($captions))
<div class="ct-active"><i class="fa fa-filter"></i>
    @foreach($captions as $caption)
    @if(!$loop->first)<span class="ct-active-sep">・</span>@endif
    <span><b>{{ $caption['label'] }}</b>: {{ $caption['value'] }}</span>
    @endforeach
</div>
@endif

{{--
    Chart box toolbar: [フィルター ▾] (the box's own chart filter, popover), the box menu [⋯]
    (sort by a field, 値ラベル, CSV export) and the runtime chart-type switcher. Handlers and styles: public/vendor/exment/js/dashboard.js, css/dashboard.css.
    The selection is kept by the JS per box and sent as bf_* / ct / cs / cd on the box request; the
    re-rendered body echoes it back, so state survives every reload. ct / cs / cd are also remembered
    per user (DashboardBoxController::chartState); the chart filter (bf_*) is page-lifetime.

    $types         type => label of the switchable types ([] = no switcher)
    $current_type  the rendered type
    $configured_type  the box's own type: picking it on the switcher drops the remembered choice
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
<div class="exment-chart-toolbar">
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
    <select class="exment-ct-switch ct-sel" data-configured="{{ $configured_type }}" title="{{ exmtrans('dashboard.dashboard_box_options.chart_type') }}">
        @foreach($types as $type => $label)
        <option value="{{ $type }}" {{ $type === $current_type ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    @endif
    @if($menu)
    <div class="ct-item">
        {{-- the box menu (Power BI's "…"): sort by a field, display options, export; monochrome, colored only while open --}}
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
            @if($labels_available)
            <h4 class="cs-sec">{{ exmtrans('dashboard.chart_menu.display') }}</h4>
            <ul class="cs-list">
                <li class="cs-row{{ $display_labels ? ' on' : '' }}" data-cd-toggle="labels"><i class="fa fa-check"></i><span>{{ exmtrans('dashboard.chart_menu.labels') }}</span></li>
            </ul>
            @endif
            <ul class="cs-list cs-sep">
                <li class="cs-row" data-cd-export><i class="fa fa-download"></i><span>{{ exmtrans('dashboard.chart_menu.export') }}</span></li>
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

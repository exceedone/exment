{{--
    Chart box toolbar: [フィルター ▾] (the box's own chart filter, popover) and the runtime
    chart-type switcher. Handlers and styles: public/vendor/exment/js/dashboard.js, css/dashboard.css.
    The selection is page-lifetime (kept by the JS per box and sent as bf_* / ct on the box request);
    the re-rendered body echoes it back, so state survives every reload.

    $types         type => label of the switchable types ([] = no switcher)
    $current_type  the rendered type
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
        <div class="ct-pop">
            <h4>{{ exmtrans('dashboard.dashboard_box_options.chart_filters') }}</h4>
            <div class="ct-fgrid">
                @foreach($fields as $field)
                <div class="ct-fitem{{ !empty($field['capped']) ? ' dis' : '' }}" @if(!empty($field['capped'])) title="{{ exmtrans('dashboard.filter_bar.narrow_first') }}" @endif>
                    <span>{{ $field['label'] }}@if($field['active'])<em class="ct-fsel">{{ $field['style'] === 'range' ? 1 : count($field['selected']) }}</em>@endif</span>
                    @if($field['style'] === 'range')
                    @php($inputType = $field['kind'] === 'number' ? 'number' : ($field['kind'] === 'text' ? 'text' : 'date'))
                    <div class="ct-frange">
                        <input type="{{ $inputType }}" class="exment-bf-range{{ $field['range']['from'] !== '' ? ' active' : '' }}" data-column="{{ $field['column'] }}" data-bound="from" value="{{ $field['range']['from'] }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_from') }}">
                        <i>–</i>
                        <input type="{{ $inputType }}" class="exment-bf-range{{ $field['range']['to'] !== '' ? ' active' : '' }}" data-column="{{ $field['column'] }}" data-bound="to" value="{{ $field['range']['to'] }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_to') }}">
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
    <select class="exment-ct-switch ct-sel" title="{{ exmtrans('dashboard.dashboard_box_options.chart_type') }}">
        @foreach($types as $type => $label)
        <option value="{{ $type }}" {{ $type === $current_type ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
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

{{--
    Dashboard filter bar (options.filter_bar). A change pushState's the dashboard URL with
    df_{column} params (one value: df_col=v; several: df_col[]=v; range: df_col[from]/[to]),
    reloads only the boxes the changed items narrow (each box's data-df-dims attribute) and
    re-renders this bar from a partial request (?_df_bar=1); boxes forward the df_ params on
    their own requests. Handlers: public/vendor/exment/js/dashboard.js.

    $dims            FilterBarView::build(): [{column, label, style, kind, active, options, selected, capped | range}]
    $can_reset       the selection differs from the configured defaults (shows the reset button)
    $reset_query     URL query リセット navigates to: the configured defaults (none: dfr=1)
    $dashboard_suuid kept on the URL across navigations
--}}
<div class="exment-df-bar" data-dashboard-suuid="{{ $dashboard_suuid }}" data-reset-query="{{ $reset_query }}">
    <div class="df-head">
        <span class="df-title"><i class="fa fa-filter"></i>{{ exmtrans('dashboard.filter_bar.title') }}</span>
    </div>
    <div class="df-fields">
        @foreach($dims as $dim)
        @php($capped = !empty($dim['capped']) && !$dim['active'])
        <div class="df-field{{ $dim['style'] === 'range' ? ' df-field-range' : '' }}" @if($capped) title="{{ exmtrans('dashboard.filter_bar.narrow_first') }}" @endif>
            <label>{{ $dim['label'] }}</label>
            @if($dim['style'] === 'range')
            @php($inputType = $dim['kind'] === 'number' ? 'number' : ($dim['kind'] === 'text' ? 'text' : 'date'))
            {{-- an input shows its typed bound, else the data's end (data-end); left at its end it filters nothing (dashboard.js filterBarUrl) --}}
            @php($min = $dim['range']['min'] ?? '')
            @php($max = $dim['range']['max'] ?? '')
            {{-- a number with two ends also gets a two-handle slider under the inputs (dashboard.js initSliders), as Power BI's numeric range slicer --}}
            @php($slider = $dim['kind'] === 'number' && $min !== '' && $max !== '' && $min !== $max)
            <div class="df-range" data-column="{{ $dim['column'] }}" @if($slider) data-slider @endif>
                <input type="{{ $inputType }}" class="df-range-input{{ $dim['range']['from'] !== '' ? ' active' : '' }}" data-bound="from" data-end="{{ $min }}" value="{{ $dim['range']['from'] !== '' ? $dim['range']['from'] : $min }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_from') }}">
                <span class="df-range-sep">–</span>
                <input type="{{ $inputType }}" class="df-range-input{{ $dim['range']['to'] !== '' ? ' active' : '' }}" data-bound="to" data-end="{{ $max }}" value="{{ $dim['range']['to'] !== '' ? $dim['range']['to'] : $max }}" placeholder="{{ exmtrans('dashboard.filter_bar.range_to') }}">
                @if($slider)<div class="df-slider-wrap"></div>@endif
            </div>
            @else
            <select class="df-select" multiple data-column="{{ $dim['column'] }}" data-placeholder="{{ exmtrans('dashboard.filter_bar.all_values') }}" {{ $capped ? 'disabled' : '' }}>
                @foreach($dim['options'] as $option)
                <option value="{{ $option['id'] }}" {{ in_array((string) $option['id'], $dim['selected'], true) ? 'selected' : '' }}>{{ $option['name'] }}</option>
                @endforeach
            </select>
            @endif
        </div>
        @endforeach
        @if($can_reset)
        {{-- laid out like a field (blank label + 32px control) so it lines up with the inputs at any zoom --}}
        <div class="df-field df-field-reset">
            <label>&nbsp;</label>
            <button type="button" class="df-reset"><i class="fa fa-times"></i>&nbsp;{{ trans('admin.reset') }}</button>
        </div>
        @endif
    </div>
</div>

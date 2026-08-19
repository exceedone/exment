{{-- One filter-bar field; shared by the hierarchy and advanced groups (see filter_bar.blade.php for the dim shape).
     Two controls (dim 'style', see FilterState::style):
       select — a MULTI-select over the dim's (cascade-scoped) values → df_{column}=v / df_{column}[]=v…
       range  — a from / to pair for number and date columns          → df_{column}[from]=… / [to]=…  --}}
@php
    $capHint = !empty($dim['capped']) && empty($dim['enabled']);
    $exclHint = !empty($dim['disabled_by'])
        ? str_replace(':label', $dim['disabled_by'], exmtrans('dashboard.filter_bar.disabled_with'))
        : null;
    $isRange = ($dim['style'] ?? 'select') === 'range';
    $selectedValues = array_map('strval', $dim['selected_values'] ?? ((($dim['selected'] ?? '') !== '') ? [$dim['selected']] : []));
    $kind = $dim['kind'] ?? 'text';
    $inputType = $kind === 'number' ? 'number' : (in_array($kind, ['date', 'datetime'], true) ? 'date' : 'text');
    $range = $dim['range'] ?? ['from' => '', 'to' => ''];
@endphp
<div class="df-field{{ $isRange ? ' df-field-range' : '' }}" @if($exclHint) title="{{ $exclHint }}" @elseif($capHint) title="{{ exmtrans('dashboard.filter_bar.narrow_first') }}" @endif>
    <label>{{ $dim['label'] }}</label>
    @if($isRange)
        {{-- from / to: a change on either input navigates; both empty = filter off --}}
        <div class="df-range" data-column="{{ $dim['column'] }}" data-parent="{{ $dim['parent'] ?? '' }}"
            data-disables="{{ implode(',', $dim['disables'] ?? []) }}">
            <input type="{{ $inputType }}" class="df-range-input" data-bound="from" value="{{ $range['from'] }}"
                placeholder="{{ exmtrans('dashboard.filter_bar.range_from') }}" {{ empty($dim['enabled']) ? 'disabled' : '' }}>
            <span class="df-range-sep">–</span>
            <input type="{{ $inputType }}" class="df-range-input" data-bound="to" value="{{ $range['to'] }}"
                placeholder="{{ exmtrans('dashboard.filter_bar.range_to') }}" {{ empty($dim['enabled']) ? 'disabled' : '' }}>
        </div>
    @else
        {{-- select2 (multiple, initialised by the bar script — closeOnSelect off so several values are
             picked in one open/close, then applied once on close; the × clears)
             data-disables → dims this one locks while selected; the bar JS clears them on change --}}
        <select class="df-select" multiple data-column="{{ $dim['column'] }}" data-parent="{{ $dim['parent'] ?? '' }}"
            data-disables="{{ implode(',', $dim['disables'] ?? []) }}"
            data-placeholder="{{ exmtrans('dashboard.filter_bar.all_values') }}" {{ empty($dim['enabled']) ? 'disabled' : '' }}>
            {{-- no_data → a master record with nothing in the data yet: still selectable (it IS a real
                 record), but labelled so nobody picks it and reads the empty dashboard as a fault --}}
            @foreach($dim['options'] as $opt)
                <option value="{{ $opt['id'] }}" @if(!empty($opt['no_data'])) data-nodata="1" @endif {{ in_array((string) $opt['id'], $selectedValues, true) ? 'selected' : '' }}>{{ $opt['name'] }}@if(!empty($opt['no_data'])) {{ exmtrans('dashboard.filter_bar.no_data') }}@endif</option>
            @endforeach
        </select>
    @endif
</div>

{{-- Unified filter sidebar for global search (Meilisearch).
     Left column of the search results page: table facets, equality facets
     (status/category), creator, created date range and numeric/date ranges. --}}
<style>
    .meili-filter .box{margin-bottom:12px;}
    .meili-filter .filter-title{font-weight:600;padding:8px 12px;border-bottom:1px solid #f4f4f4;}
    .meili-filter .list-group-item{display:flex;align-items:center;padding:8px 12px;}
    .meili-filter .list-group-item label{font-weight:normal;margin:0;flex:1;display:flex;align-items:center;gap:6px;cursor:pointer;}
    .meili-filter .list-group-item .badge{margin-left:auto;}
    .meili-filter-actions{display:flex;gap:6px;margin-bottom:12px;}
    .meili-filter .list-group-item.meili-more{border:0;color:#999;font-size:12px;cursor:pointer;}
    .meili-filter .list-group-item.meili-more:hover{color:#3c8dbc;}
    .meili-filter .list-group-item.meili-hidden{display:none;}
</style>
<div class="meili-filter">
    <form method="GET" action="{{ $action }}">
        <input type="hidden" name="query" value="{{ $query }}">
        @if (!empty($sort))
            <input type="hidden" name="sort" value="{{ $sort }}">
        @endif

        <div class="meili-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm">{{ trans('admin.filter') }}</button>
            <a href="{{ $clearUrl }}" class="btn btn-default btn-sm">{{ trans('admin.reset') }}</a>
        </div>

        {{-- Checkbox groups: tables, equality facets, creators --}}
        @php $visibleLimit = 6; @endphp
        @foreach ($checkboxGroups as $group)
            <div class="box box-primary">
                <div class="filter-title">{{ $group['title'] }}</div>
                <div class="box-body" style="padding:0;">
                    <ul class="list-group" style="margin-bottom:0;">
                        @foreach ($group['items'] as $i => $item)
                            {{-- Long group: show only the first 6 rows (checked rows always shown); the rest are collapsed behind a "show more" row. --}}
                            <li class="list-group-item @if ($i >= $visibleLimit && !$item['checked']) meili-hidden @endif">
                                <label>
                                    <input type="checkbox" name="{{ $group['name'] }}" value="{{ $item['value'] }}" @if ($item['checked']) checked @endif>
                                    <span style="flex:1;">{{ $item['label'] }}</span>
                                </label>
                                <span class="badge bg-blue">{{ $item['count'] }}</span>
                            </li>
                        @endforeach
                        @php
                            $hiddenCount = count(array_filter(
                                array_slice($group['items'], $visibleLimit),
                                fn ($item) => !$item['checked']
                            ));
                        @endphp
                        @if ($hiddenCount > 0)
                            <li class="list-group-item meili-more">{{ sprintf(exmtrans('search.filter_more'), $hiddenCount) }}</li>
                        @endif
                    </ul>
                </div>
            </div>
        @endforeach

        {{-- Created date range --}}
        <div class="box box-primary">
            <div class="filter-title">{{ exmtrans('common.created_at') }}</div>
            <div class="box-body">
                <div class="input-group input-group-sm" style="margin-bottom:6px;">
                    <span class="input-group-addon">{{ exmtrans('search.filter_date_from') }}</span>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control">
                </div>
                <div class="input-group input-group-sm">
                    <span class="input-group-addon">{{ exmtrans('search.filter_date_to') }}</span>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control">
                </div>
            </div>
        </div>

        {{-- Numeric/date range columns configured by the admin --}}
        @foreach ($ranges as $range)
            <div class="box box-primary">
                <div class="filter-title">{{ $range['label'] }}</div>
                <div class="box-body">
                    <input type="{{ $range['type'] }}" name="range[{{ $range['field'] }}][from]" value="{{ $range['from'] }}" class="form-control input-sm" style="margin-bottom:4px;" placeholder="{{ exmtrans('search.range_min') }}">
                    <input type="{{ $range['type'] }}" name="range[{{ $range['field'] }}][to]" value="{{ $range['to'] }}" class="form-control input-sm" placeholder="{{ exmtrans('search.range_max') }}">
                </div>
            </div>
        @endforeach
    </form>
</div>
<script>
$(function () {
    // "show more" row -> expand the rest of the group.
    $('.meili-filter').on('click', '.meili-more', function () {
        $(this).siblings('.meili-hidden').removeClass('meili-hidden');
        $(this).remove();
    });
    // Ticking a checkbox applies the filter immediately (no button needed); date/range still use the apply button.
    $('.meili-filter').on('change', 'input[type=checkbox]', function () {
        $(this).closest('form').submit();
    });
});
</script>

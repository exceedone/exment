<div class="box box-info box-search">
    <div class="box-header with-border">
        <h3 class="box-title">{{ sprintf(exmtrans('search.result_label'), $query) }}
            <small class="result-meta meili-result-meta" data-unit="{{ exmtrans('search.result_unit') }}"></small>
        </h3>
        @if(isset($sort))
        {{-- Sort: applies to all tables; changing it reloads the page with the sort param. --}}
        <div class="box-tools pull-right">
            <select class="form-control input-sm meili-sort" style="display:inline-block;width:auto;">
                <option value="relevance" @if($sort === 'relevance') selected @endif>{{ exmtrans('search.sort_relevance') }}</option>
                <option value="newest" @if($sort === 'newest') selected @endif>{{ exmtrans('search.sort_newest') }}</option>
                <option value="oldest" @if($sort === 'oldest') selected @endif>{{ exmtrans('search.sort_oldest') }}</option>
            </select>
        </div>
        @endif
    </div>
    <!-- /.box-header -->

    <div class="box-body">
        {{-- Applied filters (chip) + clear all --}}
        @if(!empty($appliedChips))
        <div class="meili-applied">
            @foreach($appliedChips as $chip)
            <a href="{{ $chip['url'] }}" class="applied-chip">{{ $chip['label'] }} <i class="fa fa-times"></i></a>
            @endforeach
            <a href="{{ $clearUrl }}" class="result-meta">{{ exmtrans('search.filter_clear_all') }}</a>
        </div>
        @endif

        @foreach($tables as $table)
        <div class="box box-search" style="{{ $table['box_sytle'] }}" data-box_key="{{ $table['box_key'] }}">
            <div class="overlay"><i class="fa fa-refresh fa-spin"></i></div>
            <div class="box-header with-border">
                <h3 class="box-title">
                    @if(isset($table['icon']))
                    <i class="fa {{ $table['icon'] }}"></i>
                    @endif
                    {{ $table['table_view_name'] }}
                    <small class="result-meta meili-box-count"></small>
                </h3>
                <div class="box-tools pull-right">
                    {{-- Per-table export: keep the keyword + applied filter. --}}
                    @if(!empty($exportBase) && !empty($table['can_export']))
                    <div class="btn-group meili-export">
                        <button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown">
                            <i class="fa fa-download"></i> {{ exmtrans('search.export') }} <span class="caret"></span>
                        </button>
                        {{-- target=_blank: pjax skips links with a target, otherwise the binary file
                             would be injected into the DOM instead of being downloaded. --}}
                        <ul class="dropdown-menu dropdown-menu-right" role="menu">
                            <li><a href="{{ $exportBase }}&table_name={{ $table['table_name'] }}&format=csv" target="_blank" rel="noopener" download>CSV</a></li>
                            <li><a href="{{ $exportBase }}&table_name={{ $table['table_name'] }}&format=xlsx" target="_blank" rel="noopener" download>Excel (xlsx)</a></li>
                        </ul>
                    </div>
                    @endif
                    <button type="button" class="btn btn-box-tool" data-widget="collapse">
                        <i class="fa fa-minus"></i>
                    </button>
                    <button type="button" class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
                </div>
            </div>
            <div class="box-body" style="min-height:100px;">
                <div class="box-body-inner">
                    <div class="box-body-inner-header box-body-inneritem"></div>
                    <div class="table-responsive" style="width:100%; padding-top:0.5em;">
                        <div class="box-body-inner-body box-body-inneritem"></div>
                    </div>
                    <div class="box-body-inner-footer box-body-inneritem"></div>
                </div>
            </div>
        </div>
        @endforeach

        {{-- Table boxes with no result are hidden; if all are hidden, show this line. --}}
        <p class="meili-empty result-meta" style="display:none;text-align:center;margin:6px 0;">{{ exmtrans('search.no_result') }}</p>
    </div>
    <!-- /.box-body -->

    @if(isset($query))
    <input type="hidden" class="base_query" value="{{ $query }}" />
    @endif
    @if(isset($value_id))
    <input type="hidden" class="value_id" value="{{ $value_id }}" />
    @endif
    @if(isset($table_name))
    <input type="hidden" class="table_name" value="{{ $table_name }}" />
    @endif
    <input type="hidden" class="tables" value="{{ json_encode($tables) }}" />
</div>

<style type="text/css">
    .box-search .result-meta { color:#999; font-size:12px; font-weight:normal; }
    .meili-applied { margin-bottom:10px; }
    .meili-applied .applied-chip { display:inline-block; background:#e7edf6; border-radius:12px; padding:2px 10px; font-size:12px; margin:0 4px 4px 0; color:#334; text-decoration:none; }
    .meili-applied .applied-chip:hover { background:#d8e2ef; }
    .meili-applied .applied-chip .fa { color:#999; margin-left:4px; }
    .box-search .box-body .box-body-inner-header { text-align:right; }
    .box-search .meili-export { margin-right:6px; }
</style>
<script type="text/javascript">
$(function () {
    $('.meili-sort').on('change', function () {
        const params = new URLSearchParams(window.location.search);
        if ($(this).val() === 'relevance') {
            params.delete('sort');
        } else {
            params.set('sort', $(this).val());
        }
        params.delete('page');
        window.location.search = params.toString();
    });
});
</script>

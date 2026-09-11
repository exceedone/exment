@php
    // The checkbox is drawn on EVERY row, exactly like the notification list: the batch menu
    // can mark rows as seen as well as delete them, so it is not a delete-only control.
    $hasRows = $rows->count() > 0;

    // custom_table_id => the delete endpoint of that table. An id only means something inside
    // one table, so a multi-row delete has to be grouped by this. table_url comes from
    // getUrl(['list' => true]), the very base each row's own url is built on.
    $tableUrls = $rows->pluck('table_url', 'custom_table_id');
@endphp
<div class="box box-default workflow-task-list">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ exmtrans('workflow_task.header') }}
            <small>
                {{ exmtrans('workflow_task.count', $total) }}
                @if($unseenTotal > 0)
                    / <span class="label label-danger">{{ exmtrans('workflow_task.unseen_count', $unseenTotal) }}</span>
                @endif
            </small>
        </h3>
        <div class="box-tools pull-right">
            {{-- the same batch menu notify_navbar has: swal confirm, then an ajax post.
                 The list built it in the controller, so the filter is already in the urls. --}}
            {!! (new \Exceedone\Exment\Form\Tools\SwalMenuButton($menulist))->render() !!}
        </div>
    </div>

    @if($hasRows)
        {{-- The tools row of a grid screen, with the markup admin::grid.batch-actions renders:
             the select-all box, and next to it the button group that appears once something is
             checked and holds the batch actions. Same classes, so the same styling and the same
             place on the screen as on the notification list. --}}
        <div class="box-header with-border">
            <div class="pull-left">
                <input type="checkbox" class="grid-select-all" />&nbsp;
                <div class="btn-group grid-select-all-btn" style="display:none;margin-right: 5px;">
                    <a class="btn btn-sm btn-default"><span class="hidden-xs selected"></span></a>
                    <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown">
                        <span class="caret"></span>
                        <span class="sr-only">Toggle Dropdown</span>
                    </button>
                    <ul class="dropdown-menu" role="menu">
                        <li><a href="#" class="grid-batch-0">{{ trans('admin.batch_delete') }}</a></li>
                        <li><a href="#" class="grid-batch-1">{{ exmtrans('workflow_task.check_selected') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Filter and free-word search. A plain GET form: every condition ends up in the query
         string, so the paginator, the per-page box and the sort links keep it without any
         extra state, and a filtered list can be bookmarked or sent to a colleague. --}}
    <div class="box-body" style="padding-bottom:0;">
        <form method="get" action="{{ admin_url('workflow_task') }}" class="form-inline">
            <div class="form-group" style="margin-right:8px; margin-bottom:8px;">
                <select name="custom_table_id" class="form-control input-sm">
                    <option value="">{{ exmtrans('workflow_task.table') }}: {{ trans('admin.all') }}</option>
                    @foreach($tableOptions as $tableId => $tableLabel)
                        <option value="{{ $tableId }}" {{ $filter['custom_table_id'] == $tableId ? 'selected' : '' }}>{{ $tableLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin-right:8px; margin-bottom:8px;">
                <select name="seen" class="form-control input-sm">
                    <option value="">{{ exmtrans('workflow_task.seen_flg') }}: {{ trans('admin.all') }}</option>
                    <option value="0" {{ $filter['seen'] === 0 ? 'selected' : '' }}>{{ exmtrans('workflow_task.seen_options.0') }}</option>
                    <option value="1" {{ $filter['seen'] === 1 ? 'selected' : '' }}>{{ exmtrans('workflow_task.seen_options.1') }}</option>
                </select>
            </div>

            <div class="form-group" style="margin-right:8px; margin-bottom:8px;">
                <label class="control-label" style="font-weight:100; margin-right:4px;">
                    <small>{{ exmtrans('workflow_task.updated_at') }}</small>
                </label>
                <input type="date" name="from" class="form-control input-sm" value="{{ $filter['from'] }}" style="width:150px;" />
                <span>&nbsp;~&nbsp;</span>
                <input type="date" name="to" class="form-control input-sm" value="{{ $filter['to'] }}" style="width:150px;" />
            </div>

            <div class="form-group" style="margin-right:8px; margin-bottom:8px;">
                <input type="text" name="q" class="form-control input-sm" style="width:200px;"
                       maxlength="{{ \Exceedone\Exment\Services\Workflow\WorkflowTaskService::MAX_KEYWORD_LENGTH }}"
                       placeholder="{{ exmtrans('search.freeword') }}" value="{{ $filter['q'] }}" />
            </div>

            {{-- the sort belongs to the view, not to the filter: submitting the form must not
                 silently throw away the direction the user picked in the table header --}}
            <input type="hidden" name="sort" value="{{ $filter['sort'] }}" />
            <input type="hidden" name="{{ $perPageName }}" value="{{ $perPage }}" />

            <div class="form-group" style="margin-bottom:8px;">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fa fa-search"></i> {{ trans('admin.search') }}
                </button>
                @if($isFiltered)
                    <a href="{{ admin_url('workflow_task') }}" class="btn btn-sm btn-default">
                        <i class="fa fa-undo"></i> {{ trans('admin.reset') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead>
                <tr>
                    {{-- the row selector column of a grid: first, narrow and without a label --}}
                    <th class="column-__row_selector__" style="width: 40px;">&nbsp;</th>
                    <th style="width: 70px;">{{ exmtrans('workflow_task.seen_flg') }}</th>
                    <th>{{ exmtrans('workflow_task.table') }}</th>
                    <th>{{ exmtrans('workflow_task.data') }}</th>
                    <th>{{ exmtrans('workflow_task.status') }}</th>
                    <th>
                        {{-- the only sortable column: it is the only one the index reads, so it
                             is the only one that can be ordered without loading every record --}}
                        <a href="{{ request()->fullUrlWithQuery(['sort' => $filter['sort'] === 'asc' ? 'desc' : 'asc', 'page' => 1]) }}">
                            {{ exmtrans('workflow_task.updated_at') }}
                            <i class="fa fa-sort-amount-{{ $filter['sort'] }}"></i>
                        </a>
                    </th>
                    <th style="width: 70px;">{{ trans('admin.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    {{-- "rowclick" is the class Exment's own grids use (CommonEvent.tableHoverLink in
                         common.js): clicking anywhere in the row triggers this link, while a click on
                         the link itself stays a normal link (ctrl+click, middle click, copy address).
                         The delete button is an <a> too, so that handler leaves it alone. --}}
                    <tr @if(!$row['seen']) style="font-weight: bold;" @endif>
                        {{-- the click on this cell is stopped by the script below, so ticking a
                             box does not also open the task. data-id is the task key
                             "<custom_table_id>:<record id>", which is unique across tables -
                             two tables can both have a record 1. --}}
                        <td class="column-__row_selector__ workflow-task-check">
                            <input type="checkbox" class="grid-row-checkbox" data-id="{{ $row['task_key'] }}" />
                        </td>
                        <td>
                            @if($row['seen'])
                                <span class="label label-default">{{ exmtrans('workflow_task.seen_options.1') }}</span>
                            @else
                                <span class="label label-danger">{{ exmtrans('workflow_task.seen_options.0') }}</span>
                            @endif
                        </td>
                        <td>{{ $row['table_view_name'] }}</td>
                        <td><a class="rowclick" href="{{ admin_url('workflow_task/read') }}?key={{ urlencode($row['task_key']) }}">{{ $row['label'] }}</a></td>
                        <td>{!! $row['status_tag'] !!}</td>
                        <td>{{ $row['updated_at'] }}</td>
                        <td>
                            @if($row['can_delete'])
                                {{-- data-add-swal is Exment's confirm-then-ajax convention
                                     (CommonEvent.addShowModalEvent). The url is the record's own
                                     url and the method is DELETE, so this is the very same
                                     CustomValueController@destroy the grid delete button calls -
                                     permissions, relation checks and plugin hooks included. On
                                     success it pjax-reloads this list. --}}
                                <a href="javascript:void(0);" class="text-red"
                                   data-add-swal="{{ $row['url'] }}"
                                   data-add-swal-title="{{ trans('admin.delete_confirm') }}"
                                   data-add-swal-text="{{ $row['table_view_name'] }} : {{ $row['label'] }}"
                                   data-add-swal-method="delete"
                                   data-add-swal-confirm="{{ trans('admin.confirm') }}"
                                   data-add-swal-cancel="{{ trans('admin.cancel') }}"
                                   title="{{ trans('admin.delete') }}">
                                    <i class="fa fa-trash"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">{{ exmtrans('workflow_task.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($total > 0)
        <div class="box-footer clearfix">
            <div class="pull-left" style="margin-top: 7px;">
                {!! trans('admin.pagination.range', [
                    'first' => '<b>' . ($paginator->firstItem() ?? 0) . '</b>',
                    'last'  => '<b>' . ($paginator->lastItem() ?? 0) . '</b>',
                    'total' => '<b>' . $paginator->total() . '</b>',
                ]) !!}
            </div>

            <label class="control-label pull-right" style="margin-right: 10px; font-weight: 100;">
                <small>{{ trans('admin.show') }}</small>&nbsp;
                <select class="input-sm" onchange="location.href = this.value;">
                    @foreach($perPageOptions as $opt)
                        <option value="{{ request()->fullUrlWithQuery([$perPageName => $opt, 'page' => 1]) }}" {{ $opt == $perPage ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
                &nbsp;<small>{{ trans('admin.entries') }}</small>
            </label>

            <div class="pull-right" style="margin-right: 10px;">
                {!! $paginator->render('admin::pagination') !!}
            </div>
        </div>
    @endif
</div>

@if($hasRows)
<script type="text/javascript">
    $(function () {
        var $box = $('.workflow-task-list');
        // pjax re-runs this block with a fresh box; the flag only guards a double bind on the
        // same DOM, and it dies together with the box it is set on
        if (!$box.length || $box.data('batchBound')) {
            return;
        }
        $box.data('batchBound', true);

        // Written as JSON, not as a blade echo: a blade echo would html-escape the quotes
        // inside this script element and break the string.
        //
        // The flags are the ones blade's own json directive applies (JSON_HEX_TAG|HEX_AMP|
        // HEX_APOS|HEX_QUOT): a "<" or ">" in the data leaves as an escaped code point, so
        // nothing in it can open or close a tag. Plain json_encode() already escapes the
        // slash, so a closing script tag cannot be spelled either - but it still emits a
        // literal "<" followed by "!--", which switches the HTML parser into script-escaped
        // state. These are translation strings, so this is depth rather than a live hole,
        // and it costs no extra bytes on the values actually shipped.
        //
        // The directive is spelled out rather than used, for both payloads below. It
        // cannot carry the first one: blade compiles it by splitting its argument on every
        // comma (first part = value, second = flags, third = depth), so an inline array
        // literal loses everything after its first comma and the view stops compiling. The
        // second one could carry it - but one spelling for both beats two spellings plus a
        // paragraph on which is allowed where, and nothing else in this package or in
        // laravel-admin uses the directive at all.
        //
        // This comment sits inside a blade file AND inside a script element, so it must not
        // contain an empty blade echo, a directive name, or a closing script tag: blade and
        // the HTML parser both read comments exactly like code.
        var lang = {!! json_encode([
            'confirm_title' => trans('admin.delete_confirm'),
            'confirm'       => trans('admin.confirm'),
            'cancel'        => trans('admin.cancel'),
            'succeeded'     => trans('admin.delete_succeeded'),
            'selected'      => trans('admin.grid_items_selected'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        // custom_table_id => the delete endpoint of that table
        var tableUrls = {!! json_encode($tableUrls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
        var checkUrl = '{{ admin_url('workflow_task/rowCheck') }}';
        var token = '{{ csrf_token() }}';

        var $all = $box.find('.grid-select-all');
        var $checks = $box.find('.grid-row-checkbox');
        var $group = $box.find('.grid-select-all-btn');

        // $.admin.grid is the selection store every Exment grid uses, so this dropdown counts,
        // appears and disappears exactly like the one on the notification list
        $.admin.grid.selects = {};

        function refresh() {
            var n = $.admin.grid.selected().length;
            $group.toggle(n > 0);
            $group.find('.selected').html(lang.selected.replace('{n}', n));
        }

        function pick($check) {
            var id = $check.data('id');
            if ($check[0].checked) {
                $.admin.grid.select(id);
                $check.closest('tr').css('background-color', '#ffffd5');
            } else {
                $.admin.grid.unselect(id);
                $check.closest('tr').css('background-color', '');
            }
        }

        if (typeof $.fn.iCheck === 'function') {
            // the grid scripts split this over ifChanged and ifClicked because they want the
            // count before the state flips; ifChanged alone fires AFTER the change, so one
            // handler is both shorter and correct
            $checks.iCheck({ checkboxClass: 'icheckbox_minimal-blue' }).on('ifChanged', function () {
                pick($(this));
                refresh();
            });
            $all.iCheck({ checkboxClass: 'icheckbox_minimal-blue' }).on('ifChanged', function () {
                $checks.iCheck(this.checked ? 'check' : 'uncheck');
                refresh();
            });
        } else {
            // without iCheck the plain checkbox still works: the screen degrades, it does not break
            $checks.on('change', function () {
                pick($(this));
                refresh();
            });
            $all.on('change', function () {
                var checked = this.checked;
                $checks.each(function () {
                    this.checked = checked;
                    pick($(this));
                });
                refresh();
            });
        }

        // the whole row is a link (CommonEvent.tableHoverLink binds the click on the <tr> and
        // only lets <a> through), so without this a click on the box would open the task
        $box.find('.workflow-task-check').on('click', function (ev) {
            ev.stopPropagation();
        });

        // ------------------------------------------------------------------ batch delete
        $box.find('.grid-batch-0').on('click', function (ev) {
            ev.preventDefault();

            var groups = {};
            var total = 0;

            $.each($.admin.grid.selected(), function (i, key) {
                // the task key is "<custom_table_id>:<record id>" (WorkflowTaskService::taskKey)
                var parts = String(key).split(':');
                var url = tableUrls[parts[0]];
                if (!url) {
                    return;
                }
                if (!groups[url]) {
                    groups[url] = [];
                }
                groups[url].push(parts[1]);
                total++;
            });

            if (total === 0) {
                return;
            }

            var urls = Object.keys(groups);

            Exment.CommonEvent.ShowSwal(urls[0], {
                title: lang.confirm_title,
                text: lang.selected.replace('{n}', total),
                method: 'delete',
                confirm: lang.confirm,
                cancel: lang.cancel,
                // ShowSwal only uses the url above to decide there is something to send; the
                // requests are built here. data already carries _token and _method=delete, so
                // each one is exactly the request the single row delete makes - same
                // CustomValueController@destroy, same permission and relation checks.
                //
                // KEEP THE (data) ARGUMENT. ShowSwal reaches this through hasValue(), which
                // tests obj.length, and a function's length is its arity - a zero argument
                // postEvent reads as "empty", is skipped without a word, and the dialog posts
                // the bare url above instead.
                postEvent: function (data) {
                    return new Promise(function (resolve) {
                        var failed = [];
                        var chain = $.Deferred().resolve().promise();

                        $.each(groups, function (url, ids) {
                            chain = chain.then(function () {
                                return $.ajax({
                                    type: 'POST',
                                    url: url + '/' + ids.join(','),
                                    data: data
                                }).then(function (res) {
                                    if (!res || (res.status !== true && res.result !== true)) {
                                        failed.push(res && res.message ? res.message : url);
                                    }
                                }, function (xhr) {
                                    var body = xhr.responseJSON || {};
                                    failed.push(body.message || (xhr.status + ' ' + xhr.statusText));
                                    // one table refusing must not cancel the tables behind it
                                    return $.Deferred().resolve().promise();
                                });
                            });
                        });

                        chain.always(function () {
                            if (failed.length === 0) {
                                Exment.CommonEvent.CallbackExmentAjax({
                                    status: true,
                                    toastr: lang.succeeded
                                }, resolve);
                                return;
                            }
                            // "failed" does not mean "nothing was deleted". destroy() takes the
                            // whole comma separated list and keeps going after a row refuses
                            // (Collection::each only stops on a literal false), so rows of that
                            // very request can already be gone - and a table before it may have
                            // succeeded outright. Nothing in the answer says which, so reload
                            // either way, exactly like the standard grid batch delete does.
                            $.pjax.reload('#pjax-container');
                            Exment.CommonEvent.CallbackExmentAjax({
                                status: false,
                                message: failed.join(' / ')
                            }, resolve);
                        });
                    });
                }
            });
        });

        // ------------------------------------------------------------- batch mark as seen
        $box.find('.grid-batch-1').on('click', function (ev) {
            ev.preventDefault();

            var keys = $.admin.grid.selected();
            if (keys.length === 0) {
                return;
            }

            // no confirm dialog, exactly like the notification list: marking something as read
            // changes no data and is undone by the "mark all as unseen" menu entry
            $.ajax({
                type: 'POST',
                url: checkUrl,
                data: {
                    _token: token,
                    keys: keys.join(',')
                }
            }).then(function (res) {
                // on success CallbackExmentAjax reloads the list, so the seen labels and the
                // navbar badge are both refreshed by this one call
                Exment.CommonEvent.CallbackExmentAjax(res);
            }, function (res) {
                Exment.CommonEvent.CallbackExmentAjax(res);
            });
        });

        refresh();
    });
</script>
@endif

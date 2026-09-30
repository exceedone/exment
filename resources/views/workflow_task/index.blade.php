@php
    // The 状態 filter offers the three choices of the notification list - both, unread, read. The
    // tasks taken off the list are not one of them: they are a list of their own ($removedView),
    // opened from the filter button like the deleted data of a grid, where they are put back.
    // Its rows are put back rather than taken off, and the read / unread menu has nothing to act
    // on there.
    $seenOptions = [
        '' => trans('admin.all'),
        0 => exmtrans('workflow_task.seen_options.0'),
        1 => exmtrans('workflow_task.seen_options.1'),
    ];

    // The sorter of a grid column (Grid\Column::sorter()): the neutral icon until the user picks a
    // direction, then the direction in use, and the link asks for the other one. The first click
    // asks for the newest first - on a grid as here - which is also the other way round from the
    // default order of this list (oldest first).
    $sortIcon = $sorted ? 'fa-sort-amount-' . $filter['sort'] : 'fa-sort';
    $sortUrl = request()->fullUrlWithQuery(['sort' => ($sorted && $filter['sort'] === 'desc') ? 'asc' : 'desc', 'page' => 1]);
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
                {{-- the tasks taken off the list are still waiting for this user: the way to them
                     is in sight whenever there are any --}}
                @if(!$removedView && $removedTotal > 0)
                    / <a href="{{ $removedUrl }}">{{ exmtrans('workflow_task.removed_count', $removedTotal) }}</a>
                @endif
            </small>
        </h3>
    </div>

    {{-- The tools row of a grid screen (admin::grid.table + admin::grid.tools), with the markup
         and the classes those views render, so it looks and sits like the data grid: on the left
         the select-all box and the button group that appears once something is checked
         (admin::grid.batch-actions), the filter button (admin::filter.button) and the free-word
         box (admin::grid.quick-search); on the right the batch menu. --}}
    <div class="box-header with-border">
        <div class="pull-right">
            {{-- the same batch menu notify_navbar has: swal confirm, then an ajax post.
                 The list built it in the controller, so the filter is already in the urls. --}}
            @if(!$removedView)
                {!! (new \Exceedone\Exment\Form\Tools\SwalMenuButton($menulist))->render() !!}
            @endif
        </div>
        <div class="pull-left">
            <input type="checkbox" class="grid-select-all" />&nbsp;
            <div class="btn-group grid-select-all-btn" style="display:none;margin-right: 5px;">
                <a class="btn btn-sm btn-default"><span class="hidden-xs selected"></span></a>
                <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown">
                    <span class="caret"></span>
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu" role="menu">
                    @if($removedView)
                        <li><a href="#" class="grid-batch-2">{{ exmtrans('workflow_task.restore_selected') }}</a></li>
                    @else
                        <li><a href="#" class="grid-batch-0">{{ trans('admin.batch_delete') }}</a></li>
                        <li><a href="#" class="grid-batch-1">{{ exmtrans('workflow_task.check_selected') }}</a></li>
                    @endif
                </ul>
            </div>
            <div class="btn-group" style="margin-right: 5px" data-toggle="buttons">
                <label class="btn btn-sm btn-dropbox workflow-task-filter-btn {{ $expandFilter ? 'active' : '' }}" title="{{ trans('admin.filter') }}">
                    <input type="checkbox"><i class="fa fa-filter"></i><span class="hidden-xs">&nbsp;&nbsp;{{ trans('admin.filter') }}</span>
                </label>
                {{-- the scopes of a grid filter button (admin::filter.button), where the data grid
                     offers its deleted data: the list in use named on the button, キャンセル to
                     leave it --}}
                <button type="button" class="btn btn-sm btn-dropbox dropdown-toggle" data-toggle="dropdown">
                    <span>@if($removedView)&nbsp;{{ exmtrans('workflow_task.seen_options.2') }}&nbsp;@endif</span>
                    <span class="caret"></span>
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu" role="menu">
                    <li><a href="{{ $removedUrl }}">{{ exmtrans('workflow_task.seen_options.2') }}</a></li>
                    @if($removedView)
                        <li role="separator" class="divider"></li>
                        <li><a href="{{ $cancelUrl }}">{{ trans('admin.cancel') }}</a></li>
                    @endif
                </ul>
            </div>
            {{-- The free-word search, where the data grid has it: right of the filter button
                 (DefaultGrid::setCustomGridFilters() puts its quick search there). It looks at what
                 the データ column shows - the label columns, and "#id".
                 Unlike the grid box it stays on phones: this screen has no other free-word search. --}}
            <form action="{{ admin_url('workflow_task') }}" pjax-container style="display: inline-block;">
                <div class="input-group input-group-sm" style="display: inline-block;">
                    <input type="text" name="q" class="form-control" style="width: 200px;" value="{{ $filter['q'] }}"
                           placeholder="{{ exmtrans('search.freeword') }}"
                           maxlength="{{ \Exceedone\Exment\Services\Workflow\WorkflowTaskService::MAX_KEYWORD_LENGTH }}" />

                    <div class="input-group-btn" style="display: inline-block;">
                        <button type="submit" class="btn btn-default"><i class="fa fa-search"></i></button>
                    </div>
                </div>
                @foreach($quickSearchKeep as $keepName => $keepValue)
                    <input type="hidden" name="{{ $keepName }}" value="{{ $keepValue }}" />
                @endforeach
            </form>
        </div>
    </div>

    {{-- The filter panel of a grid (admin::filter.container): closed until the filter button opens
         it, and already open when one of its conditions is set - a grid opens it on any
         condition too - so a short list always shows what shortened it.
         A GET form: every condition ends up in the query string, so the paginator, the per-page
         box and the sort link keep it without any extra state, and a filtered list can be
         bookmarked or sent to a colleague. "pjax-container" makes laravel-admin.js send it
         through pjax, like every grid filter. --}}
    <div class="box-header with-border {{ $expandFilter ? '' : 'hide' }}" id="workflow-task-filter-box">
        <form action="{{ admin_url('workflow_task') }}" class="form-horizontal" pjax-container method="get">
            <div class="row">
                <div class="col-md-12">
                    <div class="box-body">
                        <div class="fields-group">
                            {{-- admin::filter.radio. Not in the 削除済み list, whose tasks are neither
                                 read nor unread: panelKeep carries that list instead. --}}
                            @if(!$removedView)
                            <div class="form-group">
                                <label class="col-sm-2 control-label"> {{ exmtrans('workflow_task.seen_flg') }}</label>
                                <div class="col-sm-8">
                                    <div class="input-group input-group-sm">
                                        @foreach($seenOptions as $seenValue => $seenLabel)
                                            <span class="icheck">
                                                <label class="radio-inline">
                                                    <input type="radio" class="workflow-task-filter-seen" name="seen" value="{{ $seenValue }}" {{ (string)$filter['seen'] === (string)$seenValue ? 'checked' : '' }} />&nbsp;{{ $seenLabel }}&nbsp;&nbsp;
                                                </label>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- admin::filter.select --}}
                            <div class="form-group">
                                <label class="col-sm-2 control-label"> {{ exmtrans('workflow_task.table') }}</label>
                                <div class="col-sm-8">
                                    <select class="form-control workflow-task-filter-table" name="custom_table_id" style="width: 100%;">
                                        <option></option>
                                        @foreach($tableOptions as $tableId => $tableLabel)
                                            <option value="{{ $tableId }}" {{ $filter['custom_table_id'] == $tableId ? 'selected' : '' }}>{{ $tableLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- admin::filter.select, on the status NAME the column shows (see
                                 WorkflowTaskService::statusOptions()) --}}
                            <div class="form-group">
                                <label class="col-sm-2 control-label"> {{ exmtrans('workflow_task.status') }}</label>
                                <div class="col-sm-8">
                                    <select class="form-control workflow-task-filter-status" name="status" style="width: 100%;">
                                        <option></option>
                                        @foreach($statusOptions as $statusName)
                                            <option value="{{ $statusName }}" {{ $filter['status'] === $statusName ? 'selected' : '' }}>{{ $statusName }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- admin::filter.betweenDatetime: text boxes with the datetimepicker set up by
                                 the script below, like the 更新日時 filter of the data grid. A type=date
                                 input is drawn by the browser, in the date format of the OS, whatever
                                 APP_LOCALE says. --}}
                            <div class="form-group">
                                <label class="col-sm-2 control-label">{{ exmtrans('workflow_task.updated_at') }}</label>
                                <div class="col-sm-8">
                                    <div class="input-group input-group-sm workflow-task-dates">
                                        <div class="input-group-addon">
                                            <i class="fa fa-calendar"></i>
                                        </div>
                                        <input type="text" class="form-control" placeholder="{{ exmtrans('workflow_task.updated_at') }}" autocomplete="off" name="from" value="{{ $filter['from'] }}" />
                                        <span class="input-group-addon" style="border-left: 0; border-right: 0;">-</span>
                                        <input type="text" class="form-control" placeholder="{{ exmtrans('workflow_task.updated_at') }}" autocomplete="off" name="to" value="{{ $filter['to'] }}" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box-footer">
                <div class="row">
                    <div class="col-md-12">
                        <div class="col-md-2"></div>
                        <div class="col-md-8">
                            <div class="btn-group pull-left">
                                <button class="btn btn-info submit btn-sm"><i
                                            class="fa fa-search"></i>&nbsp;&nbsp;{{ trans('admin.search') }}</button>
                            </div>
                            <div class="btn-group pull-left " style="margin-left: 10px;">
                                <a href="{{ $resetUrl }}" class="btn btn-default btn-sm"><i
                                            class="fa fa-undo"></i>&nbsp;&nbsp;{{ trans('admin.reset') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- the free word, the order and the page size are not set here: searching must not
                 silently throw away what the user picked elsewhere (see WorkflowTaskController::index) --}}
            @foreach($panelKeep as $keepName => $keepValue)
                <input type="hidden" name="{{ $keepName }}" value="{{ $keepValue }}" />
            @endforeach
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
                    {{-- the only sortable column: it is the only one the index reads, so it is the
                         only one that can be ordered without loading every record --}}
                    <th class="column-updated_at">{{ exmtrans('workflow_task.updated_at') }}<a class="fa fa-fw {{ $sortIcon }}" href="{{ $sortUrl }}"></a></th>
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
                            @if($removedView)
                                <span class="label label-warning">{{ exmtrans('workflow_task.seen_options.2') }}</span>
                            @elseif($row['seen'])
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
                            @if($removedView)
                            {{-- Puts the task back on THIS user's list, as unread
                                 (WorkflowTaskController::rowRestore). The script below posts it:
                                 nothing of the record is touched, so there is no confirm dialog. --}}
                            <a href="javascript:void(0);" class="workflow-task-restore"
                               data-key="{{ $row['task_key'] }}"
                               title="{{ exmtrans('workflow_task.restore') }}">
                                <i class="fa fa-undo"></i>
                            </a>
                            @else
                            {{-- Takes the task off THIS user's list (WorkflowTaskController::rowDelete).
                                 The row is a real record other users work on, so it is never
                                 deleted from here. data-add-swal is Exment's confirm-then-ajax
                                 convention (CommonEvent.addShowModalEvent); data-add-swal-data is
                                 read by jQuery as JSON and posted along, with the moment this list
                                 was drawn (see WorkflowTaskController::rowDelete). On success it
                                 pjax-reloads this list. --}}
                            <a href="javascript:void(0);" class="text-red"
                               data-add-swal="{{ admin_url('workflow_task/rowDelete') }}"
                               data-add-swal-data="{{ json_encode(['keys' => $row['task_key'], 'listed_at' => $listedAt]) }}"
                               data-add-swal-method="post"
                               data-add-swal-title="{{ exmtrans('workflow_task.delete_title') }}"
                               data-add-swal-text="{{ exmtrans('workflow_task.confirm_text.delete', $row['table_view_name'] . ' : ' . $row['label']) }}"
                               data-add-swal-confirm="{{ trans('admin.confirm') }}"
                               data-add-swal-cancel="{{ trans('admin.cancel') }}"
                               title="{{ trans('admin.delete') }}">
                                <i class="fa fa-trash"></i>
                            </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    {{-- under a filter, "no pending task" would be a claim about the tasks the
                         filter hides --}}
                    <tr>
                        <td colspan="7" class="text-center">
                            @if($removedView)
                                {{ $isFiltered ? exmtrans('workflow_task.empty_filtered') : exmtrans('workflow_task.empty_removed_list') }}
                            @else
                                {{ $isFiltered ? exmtrans('workflow_task.empty_filtered') : exmtrans('workflow_task.empty') }}
                                {{-- nothing on the list is not nothing to do --}}
                                @if($removedTotal > 0)
                                    <br /><a href="{{ $removedUrl }}">{{ exmtrans('workflow_task.empty_removed', $removedTotal) }}</a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($total > 0)
        {{-- the footer of a grid (Grid\Tools\Paginator): the range, the page links, the page size --}}
        <div class="box-footer table-footer clearfix">
            {!! trans('admin.pagination.range', [
                'first' => '<b>' . ($paginator->firstItem() ?? 0) . '</b>',
                'last'  => '<b>' . ($paginator->lastItem() ?? 0) . '</b>',
                'total' => '<b>' . $paginator->total() . '</b>',
            ]) !!}
            {!! $paginator->render('admin::pagination') !!}
            <label class="control-label pull-right" style="margin-right: 10px; font-weight: 100;">
                <small>{{ trans('admin.show') }}</small>&nbsp;
                <select class="input-sm workflow-task-per-pager">
                    @foreach($perPageOptions as $opt)
                        <option value="{{ request()->fullUrlWithQuery([$perPageName => $opt, 'page' => 1]) }}" {{ $opt == $perPage ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
                &nbsp;<small>{{ trans('admin.entries') }}</small>
            </label>
        </div>
    @endif
</div>

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
        // The directive is spelled out rather than used, for every payload of this view. It
        // cannot carry an inline array literal: blade compiles it by splitting its argument
        // on every comma (first part = value, second = flags, third = depth), so the literal
        // loses everything after its first comma and the view stops compiling. Nothing else
        // in this package or in laravel-admin uses the directive at all.
        //
        // This comment sits inside a blade file AND inside a script element, so it must not
        // contain an empty blade echo, a directive name, or a closing script tag: blade and
        // the HTML parser both read comments exactly like code.
        var lang = {!! json_encode([
            'delete_title'    => exmtrans('workflow_task.delete_title'),
            'delete_selected' => exmtrans('workflow_task.confirm_text.delete_selected'),
            'confirm'         => trans('admin.confirm'),
            'cancel'          => trans('admin.cancel'),
            'selected'        => trans('admin.grid_items_selected'),
            'choose'          => trans('admin.choose'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        var checkUrl = '{{ admin_url('workflow_task/rowCheck') }}';
        var deleteUrl = '{{ admin_url('workflow_task/rowDelete') }}';
        var restoreUrl = '{{ admin_url('workflow_task/rowRestore') }}';
        var token = '{{ csrf_token() }}';
        // when this list was drawn - the delete leaves alone a record somebody acted on after it
        var listedAt = {!! json_encode($listedAt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

        // ------------------------------------------------------------------- filter panel
        // The filter button of a grid (Grid\Tools\FilterButton) opens and closes the panel. The
        // button group carries data-toggle="buttons", whose click handler keeps the "active" look
        // of the button and stops the click from reaching the hidden checkbox inside it, so this
        // runs once per click.
        $box.find('.workflow-task-filter-btn').on('click', function () {
            var $panel = $('#workflow-task-filter-box');
            if ($panel.is(':visible')) {
                $panel.addClass('hide');
            } else {
                $panel.removeClass('hide');
            }
        });

        // the plugins the grid filters are drawn with (Filter\Presenter\Radio and Select); every
        // admin page loads them, and without one the plain input still submits the same value
        if (typeof $.fn.iCheck === 'function') {
            $box.find('.workflow-task-filter-seen').iCheck({ radioClass: 'iradio_minimal-blue' });
        }
        if (typeof $.fn.select2 === 'function') {
            $box.find('.workflow-task-filter-table, .workflow-task-filter-status').select2({
                placeholder: { id: '', text: lang.choose },
                allowClear: true
            });
        }

        // The options the data grid gives its 更新日時 filter (see WorkflowTaskController::index):
        // yyyy-mm-dd - the only shape normalizeFilter() accepts -, the calendar in the language
        // of APP_LOCALE, and each end limiting the other. The picker plugin is part of every
        // admin page (Form::collectFieldAssets); without it the boxes stay plain text boxes that
        // still submit yyyy-mm-dd.
        var $dates = $box.find('.workflow-task-dates');
        if ($dates.length && typeof $.fn.datetimepicker === 'function') {
            var $from = $dates.find('input[name="from"]');
            var $to = $dates.find('input[name="to"]');
            var options = {!! json_encode($dateOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
            $from.datetimepicker(options);
            $to.datetimepicker($.extend({ useCurrent: false }, options));
            $from.on('dp.change', function (e) {
                $to.data('DateTimePicker').minDate(e.date);
            });
            $to.on('dp.change', function (e) {
                $from.data('DateTimePicker').maxDate(e.date);
            });
        }

        // the page size box of a grid (Grid\Tools\PerPageSelector) reloads through pjax
        $box.find('.workflow-task-per-pager').on('change', function () {
            if (typeof $.pjax === 'function') {
                $.pjax({ url: this.value, container: '#pjax-container' });
            } else {
                location.href = this.value;
            }
        });

        // --------------------------------------------------------------------- selection
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
        // Takes the ticked tasks off THIS user's list; no record is deleted. One request to the
        // endpoint the row button uses, whatever tables the rows come from: a task key carries
        // its table, so two tables that both have a record 1 cannot be confused. Confirmed first,
        // because the tasks leave the list: they come back with the next action on their record,
        // or when the user puts them back from the 削除済み list.
        $box.find('.grid-batch-0').on('click', function (ev) {
            ev.preventDefault();

            var keys = $.admin.grid.selected();
            if (keys.length === 0) {
                return;
            }

            // ShowSwal adds _token, posts, and on success reloads the list and shows the toastr
            Exment.CommonEvent.ShowSwal(deleteUrl, {
                title: lang.delete_title,
                text: lang.delete_selected.replace('{n}', keys.length),
                confirm: lang.confirm,
                cancel: lang.cancel,
                data: {
                    keys: keys.join(','),
                    listed_at: listedAt
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
                // on success CallbackExmentAjax pjax-reloads the list, which redraws the seen
                // labels - and makes workflow_task_navbar.js fetch the badge again, since the
                // click on this menu entry was inside the list
                Exment.CommonEvent.CallbackExmentAjax(res);
            }, function (res) {
                Exment.CommonEvent.CallbackExmentAjax(res);
            });
        });

        // ------------------------------------------------------------ put back on the list
        // The row button and the batch menu of the 削除済み list. No confirm dialog, like marking
        // as seen: nothing of the record is touched, and the task can be taken off again. The
        // tasks come back unread, so the click inside the list also makes the navbar fetch the
        // badge again (workflow_task_navbar.js).
        function restore(keys) {
            $.ajax({
                type: 'POST',
                url: restoreUrl,
                data: {
                    _token: token,
                    keys: keys.join(',')
                }
            }).then(function (res) {
                Exment.CommonEvent.CallbackExmentAjax(res);
            }, function (res) {
                Exment.CommonEvent.CallbackExmentAjax(res);
            });
        }

        $box.find('.workflow-task-restore').on('click', function (ev) {
            ev.preventDefault();
            restore([String($(this).data('key'))]);
        });

        $box.find('.grid-batch-2').on('click', function (ev) {
            ev.preventDefault();

            var keys = $.admin.grid.selected();
            if (keys.length === 0) {
                return;
            }
            restore(keys);
        });

        refresh();
    });
</script>

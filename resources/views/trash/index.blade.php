{{-- The trash (ISS00334): TrashController --}}
<div class="box box-default" id="exm-trash">
    <div class="box-header with-border">
        <form method="get" action="{{ admin_url('trash') }}" class="form-inline exm-trash-filter" pjax-container>
            <div class="form-group">
                <label for="exm-trash-table">{{ exmtrans('trash.table') }}</label>
                <select class="form-control input-sm" name="table" id="exm-trash-table">
                    <option value="">{{ exmtrans('trash.all_tables') }}</option>
                    @foreach($tables as $id => $label)
                        <option value="{{ $id }}" @if($filters['table'] === strval($id)) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="exm-trash-from">{{ exmtrans('trash.deleted_at') }}</label>
                <input type="date" class="form-control input-sm" name="from" id="exm-trash-from" value="{{ $filters['from'] }}">
                <span>-</span>
                <input type="date" class="form-control input-sm" name="to" id="exm-trash-to" value="{{ $filters['to'] }}" aria-label="{{ exmtrans('trash.deleted_at') }}">
            </div>
            <div class="form-group">
                <label for="exm-trash-user">{{ exmtrans('trash.deleted_user') }}</label>
                <select class="form-control input-sm" name="user" id="exm-trash-user">
                    <option value=""></option>
                    @foreach($users as $id => $label)
                        <option value="{{ $id }}" @if($filters['user'] === strval($id)) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> {{ trans('admin.search') }}</button>
            <a href="{{ admin_url('trash') }}" class="btn btn-sm btn-default">{{ trans('admin.reset') }}</a>
        </form>
    </div>
    <div class="box-body">
        @if(count($tables) === 0 || $paginator->total() === 0)
            <p class="exm-trash-empty">{{ exmtrans('trash.empty') }}</p>
        @else
        <form method="post" id="exm-trash-form" action="{{ $restoreUrl }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="return_query" value="{{ $returnQuery }}">
            <div class="exm-trash-bulk" style="margin-bottom: 8px;">
                <button type="button" class="btn btn-sm btn-default" data-trash-action="{{ $restoreUrl }}" data-trash-confirm="{{ exmtrans('custom_value.message.restore') }}" data-trash-title="{{ exmtrans('custom_value.restore') }}" data-trash-bulk="1"><i class="fa fa-undo"></i> {{ exmtrans('trash.restore_selected') }}</button>
                <button type="button" class="btn btn-sm btn-danger" data-trash-action="{{ $deleteUrl }}" data-trash-confirm="{{ exmtrans('custom_value.message.hard_delete') }}" data-trash-title="{{ exmtrans('custom_value.hard_delete') }}" data-trash-bulk="1"><i class="fa fa-times-circle"></i> {{ exmtrans('trash.delete_selected') }}</button>
                <span class="text-muted" style="margin-left: 8px;">{{ exmtrans('trash.count', ['count' => $paginator->total()]) }}</span>
            </div>
            <div class="table-responsive">
            <table class="table table-hover exm-trash-table">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" class="exm-trash-all" aria-label="{{ exmtrans('trash.select_all') }}"></th>
                        <th>{{ exmtrans('trash.table') }}</th>
                        <th>{{ exmtrans('trash.label') }}</th>
                        <th>{{ exmtrans('trash.deleted_at') }}</th>
                        <th>{{ exmtrans('trash.deleted_user') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($paginator->items() as $item)
                    @php
                        $value = $item['custom_value'];
                        $key = $item['custom_table']->id . ':' . $item['id'];
                    @endphp
                    <tr class="exm-trash-row" data-trash-item="{{ $key }}">
                        <td><input type="checkbox" name="items[]" value="{{ $key }}" class="exm-trash-check" aria-label="{{ $key }}"></td>
                        <td>{{ $item['custom_table']->table_view_name }}</td>
                        <td>
                            @if(isset($value))
                                <a href="{{ $value->getUrl() }}?trashed=1">{{ $value->getLabel() }}</a>
                            @else
                                #{{ $item['id'] }}
                            @endif
                        </td>
                        <td>{{ $local($item['deleted_at']) }}</td>
                        <td>{{ isset($value) ? strval($value->deleted_user) : '' }}</td>
                        <td style="white-space: nowrap;">
                            <button type="button" class="btn btn-xs btn-default" data-trash-action="{{ $restoreUrl }}" data-trash-item="{{ $key }}" data-trash-confirm="{{ exmtrans('custom_value.message.restore') }}" data-trash-title="{{ exmtrans('custom_value.restore') }}"><i class="fa fa-undo"></i> {{ exmtrans('custom_value.restore') }}</button>
                            <button type="button" class="btn btn-xs btn-danger" data-trash-action="{{ $deleteUrl }}" data-trash-item="{{ $key }}" data-trash-confirm="{{ exmtrans('custom_value.message.hard_delete') }}" data-trash-title="{{ exmtrans('custom_value.hard_delete') }}"><i class="fa fa-times-circle"></i> {{ exmtrans('custom_value.hard_delete') }}</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </form>
        @if($paginator->lastPage() > 1)
            <ul class="pagination pagination-sm no-margin">
                @if($paginator->currentPage() > 1)
                    <li><a href="{{ $paginator->previousPageUrl() }}">&laquo;</a></li>
                @endif
                <li class="active"><span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span></li>
                @if($paginator->hasMorePages())
                    <li><a href="{{ $paginator->nextPageUrl() }}">&raquo;</a></li>
                @endif
            </ul>
        @endif
        @endif
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('exm-trash');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var form = document.getElementById('exm-trash-form');
    if (!form) { return; }
    var texts = { ok: @json(trans('admin.confirm')), cancel: @json(trans('admin.cancel')), none: @json(exmtrans('trash.message.select')) };
    function escape(text) {
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
    // the dialog of the admin pages (SweetAlert2), the browser's own when it is not there
    function confirmIt(title, text) {
        if (typeof window.swal !== 'function') { return Promise.resolve(window.confirm(text)); }
        return window.swal({ title: escape(title), text: text, type: 'warning', showCancelButton: true,
            confirmButtonColor: '#DD6B55', confirmButtonText: escape(texts.ok), cancelButtonText: escape(texts.cancel) })
            .then(function (r) { return !!(r && r.value); });
    }
    var all = root.querySelector('.exm-trash-all');
    if (all) {
        all.addEventListener('change', function () {
            root.querySelectorAll('.exm-trash-check').forEach(function (el) { el.checked = all.checked; });
        });
    }
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-trash-action]');
        if (!btn) { return; }
        var item = btn.getAttribute('data-trash-item');
        if (!item && !root.querySelector('.exm-trash-check:checked')) {
            if (typeof window.swal === 'function') { window.swal(escape(texts.none), '', 'info'); } else { window.alert(texts.none); }
            return;
        }
        confirmIt(btn.getAttribute('data-trash-title'), btn.getAttribute('data-trash-confirm')).then(function (ok) {
            if (!ok) { return; }
            if (item) {
                // one row: only this one is sent
                root.querySelectorAll('.exm-trash-check').forEach(function (el) { el.checked = (el.value === item); });
            }
            form.setAttribute('action', btn.getAttribute('data-trash-action'));
            form.submit();
        });
    });
})();
</script>

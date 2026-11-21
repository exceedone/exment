{{-- File server (ISS00378): the values of the columns of a folder or file, and the data it is linked to. Data from FileServerController::showNode. --}}
@php $can_edit_meta = $level >= \Exceedone\Exment\Services\FileServer\Level::EDIT; @endphp
<div id="exm-fs-meta" data-url="{{ $node_url }}" data-lookup="{{ $data_lookup_url }}">
    @if (count($fields) > 0)
        <div class="exm-fs-section box card">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-tags" aria-hidden="true"></i> {{ exmtrans('file_server.metadata') }}</h3></div>
            <div class="box-body">
                @if ($can_edit_meta)
                    <form data-fs-meta-form>
                        @foreach ($fields as $field)
                            @php $id = 'exm-fs-meta-' . $field['name']; $value = $field['value']; @endphp
                            <input type="hidden" name="meta_present[]" value="{{ $field['name'] }}">
                            <div class="form-group">
                                <label for="{{ $id }}">{{ $field['label'] }}@if ($field['required']) <span class="text-danger" aria-label="{{ exmtrans('file_server.required') }}">*</span>@endif</label>
                                @switch($field['field_type'])
                                    @case('textarea')
                                        <textarea id="{{ $id }}" name="meta[{{ $field['name'] }}]" class="form-control input-sm" rows="3" @required($field['required'])>{{ $value }}</textarea>
                                        @break
                                    @case('number')
                                        <input id="{{ $id }}" type="number" step="any" name="meta[{{ $field['name'] }}]" class="form-control input-sm" value="{{ $value }}" @required($field['required'])>
                                        @break
                                    @case('date')
                                        <input id="{{ $id }}" type="date" name="meta[{{ $field['name'] }}]" class="form-control input-sm" value="{{ $value }}" @required($field['required'])>
                                        @break
                                    @case('select')
                                        <select id="{{ $id }}" name="meta[{{ $field['name'] }}]" class="form-control input-sm" @required($field['required'])>
                                            <option value=""></option>
                                            @foreach ($field['choices'] as $choice)
                                                <option value="{{ $choice }}" @selected($value === $choice)>{{ $choice }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('multiselect')
                                        <div id="{{ $id }}">
                                            @foreach ($field['choices'] as $choice)
                                                <label style="font-weight:normal;margin-right:10px"><input type="checkbox" name="meta[{{ $field['name'] }}][]" value="{{ $choice }}" @checked(in_array($choice, (array) $value, true))> {{ $choice }}</label>
                                            @endforeach
                                        </div>
                                        @break
                                    @case('boolean')
                                        <select id="{{ $id }}" name="meta[{{ $field['name'] }}]" class="form-control input-sm">
                                            <option value=""></option>
                                            <option value="1" @selected($value === true)>{{ exmtrans('file_server.yes') }}</option>
                                            <option value="0" @selected($value === false)>{{ exmtrans('file_server.no') }}</option>
                                        </select>
                                        @break
                                    @default
                                        <input id="{{ $id }}" type="text" name="meta[{{ $field['name'] }}]" class="form-control input-sm" value="{{ $value }}" maxlength="1000" @required($field['required'])>
                                @endswitch
                            </div>
                        @endforeach
                        <button type="submit" class="btn btn-sm btn-primary">{{ exmtrans('common.save') }}</button>
                    </form>
                @else
                    <table class="table table-condensed exm-fs-meta-table" style="margin:0">
                        @foreach ($fields as $field)
                            <tr><th>{{ $field['label'] }}</th><td style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $field['display'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
            </div>
        </div>
    @endif

    @if (count($data_links) > 0 || count($data_tables) > 0)
        <div class="exm-fs-section box card">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-link" aria-hidden="true"></i> {{ exmtrans('file_server.data_links') }}</h3></div>
            <div class="box-body">
                @if (count($data_links) === 0)
                    <p class="exm-fs-muted">{{ exmtrans('file_server.data_links_none') }}</p>
                @else
                    <ul class="list-unstyled" style="margin-bottom:8px">
                        @foreach ($data_links as $link)
                            <li style="margin-bottom:4px;overflow-wrap:anywhere">
                                <span class="exm-fs-badge">{{ $link['table_label'] }}</span>
                                <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                                @if ($can_edit_meta)
                                    <button type="button" class="btn btn-xs btn-link" data-fs-unlink="{{ $node_url }}/data_links/{{ $link['id'] }}/remove" title="{{ exmtrans('file_server.data_link_remove') }}" aria-label="{{ exmtrans('file_server.data_link_remove') }}"><i class="fa fa-times" aria-hidden="true"></i></button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (count($data_tables) > 0)
                    <form data-fs-data-form>
                        <div class="form-group" style="margin-bottom:6px">
                            <label for="exm-fs-data-table" class="exm-fs-muted">{{ exmtrans('file_server.data_link_table') }}</label>
                            <select id="exm-fs-data-table" name="table" class="form-control input-sm">
                                @foreach ($data_tables as $table)
                                    <option value="{{ $table['name'] }}">{{ $table['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:6px">
                            <label for="exm-fs-data-q" class="exm-fs-muted">{{ exmtrans('file_server.data_link_search') }}</label>
                            <input id="exm-fs-data-q" type="search" class="form-control input-sm" data-fs-data-q autocomplete="off">
                            <select name="data_id" class="form-control input-sm" data-fs-data-list size="5" style="margin-top:4px" aria-label="{{ exmtrans('file_server.data_link_search') }}"></select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-default"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.data_link_add') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-meta');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    var url = root.dataset.url;

    var metaForm = root.querySelector('[data-fs-meta-form]');
    if (metaForm) {
        metaForm.addEventListener('submit', function (e) {
            e.preventDefault();
            Fs.post(url + '/metadata', new FormData(metaForm), true).then(Fs.done, Fs.fail);
        });
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-fs-unlink]');
        if (b) {
            Fs.confirm(@json(exmtrans('file_server.confirm_data_link_remove'))).then(function (ok) {
                if (ok) { Fs.post(b.getAttribute('data-fs-unlink'), {}).then(Fs.done, Fs.fail); }
            });
        }
    });

    var dataForm = root.querySelector('[data-fs-data-form]');
    if (dataForm) {
        var table = dataForm.querySelector('[name="table"]');
        var q = dataForm.querySelector('[data-fs-data-q]');
        var list = dataForm.querySelector('[data-fs-data-list]');
        var timer = null;
        var seq = 0;
        var load = function () {
            var mine = ++seq;
            var u = root.dataset.lookup + '?table=' + encodeURIComponent(table.value) + '&q=' + encodeURIComponent(q.value);
            Fs.send('GET', u).then(function (json) {
                if (mine !== seq) { return; }
                list.innerHTML = '';
                (json.items || []).forEach(function (item) {
                    var o = document.createElement('option');
                    o.value = item.id;
                    o.textContent = item.label + ' (#' + item.id + ')';
                    list.appendChild(o);
                });
            }, Fs.fail);
        };
        q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 300); });
        table.addEventListener('change', load);
        load();
        dataForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!list.value) { Fs.notify(@json(exmtrans('file_server.data_link_choose')), false); return; }
            Fs.post(url + '/data_links', { table: table.value, data_id: list.value }).then(Fs.done, Fs.fail);
        });
    }
})();
</script>

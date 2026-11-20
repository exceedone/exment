{{-- File server (ISS00378): the search of the folders and files. Data from FileServerMetaController::search. --}}
@include('exment::file_server._assets')
<div class="exm-fs" id="exm-fs-search">
    <div class="exm-fs-breadcrumb">
        <a href="{{ admin_url('file_server') }}"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('file_server.header') }}</a>
        <span>{{ exmtrans('file_server.search') }}</span>
    </div>
    <form class="exm-fs-filters" method="get" action="{{ $action }}" role="search">
        <div class="input-group input-group-sm" style="flex:1;max-width:520px">
            <input type="search" name="q" class="form-control" value="{{ $q }}" placeholder="{{ exmtrans('file_server.search_placeholder') }}" aria-label="{{ exmtrans('file_server.search') }}" autofocus>
            <span class="input-group-btn"><button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('file_server.search') }}</button></span>
        </div>
        <select name="workspace" class="form-control input-sm" style="max-width:260px" aria-label="{{ exmtrans('file_server.workspaces') }}">
            <option value="">{{ exmtrans('file_server.search_all_workspaces') }}</option>
            @foreach ($workspaces as $ws)
                <option value="{{ $ws['id'] }}" @selected($workspace_id === $ws['id'])>{{ $ws['name'] }}</option>
            @endforeach
        </select>
    </form>

    @if ($q !== '')
        <div class="box card">
            <div class="box-header"><span class="exm-fs-muted">{{ exmtrans('file_server.search_count', ['count' => count($rows)]) }}</span></div>
            <div class="box-body table-responsive p-0">
                <table class="table table-hover exm-fs-table">
                    <tbody>
                        @if (count($rows) === 0)
                            <tr><td class="exm-fs-muted">{{ exmtrans('file_server.search_none') }}</td></tr>
                        @endif
                        @foreach ($rows as $row)
                            <tr>
                                <td class="exm-fs-name">
                                    <a href="{{ $row['url'] }}">
                                        @if ($row['thumbnail_url'])
                                            <img class="exm-fs-thumb" src="{{ $row['thumbnail_url'] }}" alt="" loading="lazy">
                                        @else
                                            <i class="fa {{ $row['icon'] }}" aria-hidden="true"></i>
                                        @endif{{ $row['name'] }}</a>
                                    <div class="exm-fs-muted">{{ $row['path'] }}</div>
                                    @if ($row['snippet'] !== '')
                                        <div class="exm-fs-snippet" data-fs-snippet>@if ($row['in_content'])<i class="fa fa-file-text-o" aria-hidden="true" title="{{ exmtrans('file_server.search_in_content') }}"></i> @endif{{ $row['snippet'] }}</div>
                                    @endif
                                </td>
                                <td class="exm-fs-muted" style="white-space:nowrap">{{ optional($row['updated_at'])->format('Y/m/d H:i') }}<br>{{ $row['updated_user'] }}</td>
                                <td class="exm-fs-actions">
                                    @if (!$row['is_folder'])
                                        <a class="btn btn-xs btn-default" href="{{ $row['download_url'] }}" title="{{ exmtrans('file_server.download') }}"><i class="fa fa-download" aria-hidden="true"></i></a>
                                    @endif
                                    <a class="btn btn-xs btn-default" href="{{ $row['detail_url'] }}" title="{{ exmtrans('file_server.detail') }}"><i class="fa fa-info-circle" aria-hidden="true"></i></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-search');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    // mark the words in the parts of the text (the text is set as text, never as HTML)
    var words = @json(array_values(array_filter(preg_split('/[\s\x{3000}]+/u', $q) ?: [])));
    if (!words.length) { return; }
    var escaped = words.map(function (w) { return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
    var re = new RegExp('(' + escaped.join('|') + ')', 'gi');
    root.querySelectorAll('[data-fs-snippet]').forEach(function (el) {
        Array.prototype.slice.call(el.childNodes).forEach(function (node) {
            if (node.nodeType !== 3) { return; }
            var parts = node.textContent.split(re);
            if (parts.length < 2) { return; }
            var frag = document.createDocumentFragment();
            parts.forEach(function (part, i) {
                if (i % 2 === 1) { var m = document.createElement('mark'); m.textContent = part; frag.appendChild(m); }
                else if (part) { frag.appendChild(document.createTextNode(part)); }
            });
            node.parentNode.replaceChild(frag, node);
        });
    });
})();
</script>

{{-- File server: the trash of a workspace. Data from FileServerController::trash. --}}
@include('exment::file_server._assets')
<div class="exm-fs" id="exm-fs-trash">
    <div class="exm-fs-breadcrumb">
        <a href="{{ admin_url('file_server') }}"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('file_server.header') }}</a>
        <a href="{{ $base_url }}">{{ $workspace->name }}</a>
        <span>{{ exmtrans('file_server.trash') }}</span>
    </div>
    <p class="exm-fs-muted">
        @if ($trash_days > 0)
            {{ exmtrans('file_server.trash_note_days', ['days' => $trash_days]) }}
        @else
            {{ exmtrans('file_server.trash_note_keep') }}
        @endif
    </p>
    <div class="box card">
        <div class="box-body table-responsive p-0">
            <table class="table exm-fs-table">
                <thead><tr><th>{{ exmtrans('file_server.name') }}</th><th>{{ exmtrans('file_server.trashed_at') }}</th><th>{{ exmtrans('file_server.size') }}</th><th></th></tr></thead>
                <tbody>
                    @if (count($items) === 0)
                        <tr><td colspan="4" class="exm-fs-muted">{{ exmtrans('file_server.trash_empty') }}</td></tr>
                    @endif
                    @foreach ($items as $item)
                        <tr>
                            <td class="exm-fs-name"><i class="fa {{ $item['is_folder'] ? 'fa-folder' : 'fa-file-o' }}" aria-hidden="true"></i>{{ $item['name'] }}</td>
                            <td class="exm-fs-muted">{{ optional($item['trashed_at'])->format('Y/m/d H:i') }} {{ $item['trashed_user'] }}</td>
                            <td class="exm-fs-muted">@if (!$item['is_folder'])<span data-fs-size="{{ $item['size'] }}"></span>@endif</td>
                            <td class="exm-fs-actions">
                                <button type="button" class="btn btn-xs btn-default" data-fs-post="{{ $item['restore_url'] }}"><i class="fa fa-undo" aria-hidden="true"></i> {{ exmtrans('file_server.restore') }}</button>
                                @if ($item['purge_url'])
                                    <button type="button" class="btn btn-xs btn-danger" data-fs-post="{{ $item['purge_url'] }}" data-fs-confirm="1"><i class="fa fa-times" aria-hidden="true"></i> {{ exmtrans('file_server.purge') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-trash');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    var confirmText = @json(exmtrans('file_server.confirm_purge'));
    root.querySelectorAll('[data-fs-size]').forEach(function (el) { el.textContent = Fs.size(el.getAttribute('data-fs-size')); });
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fs-post]');
        if (!btn) { return; }
        if (btn.getAttribute('data-fs-confirm') && !confirm(confirmText)) { return; }
        Fs.post(btn.getAttribute('data-fs-post'), {}).then(Fs.done, Fs.fail);
    });
})();
</script>

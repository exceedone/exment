{{-- File server, phase 5 (ISS00379): the files that wait for an approval. Data from FileServerGovernanceController::approvals. --}}
@include('exment::file_server._assets')
<div class="exm-fs" id="exm-fs-approvals">
    <div class="exm-fs-toolbar">
        <a class="btn btn-sm btn-default" href="{{ $back_url }}"><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ $workspace->name }}</a>
    </div>
    <div class="table-responsive">
        <table class="table table-condensed exm-fs-table">
            <thead><tr><th>{{ exmtrans('file_server.name') }}</th><th>{{ exmtrans('file_server.created_at') }}</th><th>{{ exmtrans('file_server.size') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td style="overflow-wrap:anywhere"><a href="{{ $row['url'] }}">{{ $row['name'] }}</a></td>
                        <td class="exm-fs-muted">{{ optional($row['created_at'])->format('Y/m/d H:i') }} {{ $row['user'] }}</td>
                        <td class="exm-fs-muted"><span data-fs-size="{{ $row['size'] }}"></span></td>
                        <td class="exm-fs-actions">
                            <button type="button" class="btn btn-xs btn-success" data-fs-decide="{{ $row['approve_url'] }}"><i class="fa fa-check" aria-hidden="true"></i> {{ exmtrans('file_server.approve') }}</button>
                            <button type="button" class="btn btn-xs btn-warning" data-fs-decide="{{ $row['reject_url'] }}" data-fs-ask="1"><i class="fa fa-reply" aria-hidden="true"></i> {{ exmtrans('file_server.reject') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="exm-fs-muted">{{ exmtrans('file_server.approvals_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-approvals');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    root.querySelectorAll('[data-fs-size]').forEach(function (el) { el.textContent = Fs.size(el.getAttribute('data-fs-size')); });
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fs-decide]');
        if (!btn) { return; }
        var asked = btn.getAttribute('data-fs-ask') ? Fs.ask(@json(exmtrans('file_server.approval_comment')), '') : Promise.resolve('');
        asked.then(function (comment) {
            if (comment === null) { return; }
            Fs.post(btn.getAttribute('data-fs-decide'), { comment: comment }).then(Fs.done, Fs.fail);
        });
    });
})();
</script>

{{-- File server, phase 5 (ISS00379): the approval, the rules (legal hold, approval), the follow and the comments of a folder or file. Data from FileServerController::showNode. --}}
@php
    $gCanManage = $level >= \Exceedone\Exment\Services\FileServer\Level::MANAGE;
@endphp
<div id="exm-fs-governance">
    @if ($approval_status !== null)
        <div class="exm-fs-section box card">
            <div class="box-header"><h4><i class="fa fa-check-square-o" aria-hidden="true"></i> {{ exmtrans('file_server.approval') }}</h4></div>
            <div class="box-body">
                <p><span class="exm-fs-badge">{{ exmtrans('file_server.approval_' . $approval_status) }}</span>
                    @if ($approval_status !== 'draft' && $approval_user !== '')
                        <span class="exm-fs-muted">{{ $approval_user }} {{ optional($approval_at)->format('Y/m/d H:i') }}</span>
                    @endif
                </p>
                @if ($approval_comment)
                    <p style="overflow-wrap:anywhere;white-space:pre-wrap">{{ $approval_comment }}</p>
                @endif
                @if ($approval_status === 'draft')
                    <p class="exm-fs-muted">{{ exmtrans('file_server.approval_draft_help') }}</p>
                @elseif ($approval_status === 'rejected')
                    <p class="exm-fs-muted">{{ exmtrans('file_server.approval_rejected_help') }}</p>
                @endif
                @if ($can_approve)
                    <textarea class="form-control input-sm" rows="2" maxlength="1000" data-fs-approval-comment placeholder="{{ exmtrans('file_server.approval_comment') }}" aria-label="{{ exmtrans('file_server.approval_comment') }}"></textarea>
                    <div style="margin-top:6px">
                        <button type="button" class="btn btn-sm btn-success" data-fs-decide="{{ $approve_url }}"><i class="fa fa-check" aria-hidden="true"></i> {{ exmtrans('file_server.approve') }}</button>
                        <button type="button" class="btn btn-sm btn-warning" data-fs-decide="{{ $reject_url }}"><i class="fa fa-reply" aria-hidden="true"></i> {{ exmtrans('file_server.reject') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="exm-fs-section box card">
        <div class="box-header"><h4><i class="fa fa-shield" aria-hidden="true"></i> {{ exmtrans('file_server.rules') }}</h4></div>
        <div class="box-body">
            @if ($held)
                <p><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('file_server.held_help') }}</p>
            @endif
            @if (isset($retained_until))
                <p class="exm-fs-muted">{{ exmtrans('file_server.retained_until', ['date' => $retained_until->format('Y/m/d')]) }}</p>
            @endif
            @if (!$can_download)
                <p class="exm-fs-muted">{{ exmtrans('file_server.download_not_allowed_help') }}</p>
            @endif
            @if ($gCanManage)
                <form data-fs-rules-form>
                    <label style="font-weight:normal;display:block"><input type="checkbox" name="legal_hold" value="1" @checked($node->legal_hold)> {{ exmtrans('file_server.legal_hold') }}</label>
                    @if ($node->isFolder())
                        <label style="font-weight:normal;display:block"><input type="checkbox" name="requires_approval" value="1" @checked($node->requires_approval)> {{ exmtrans('file_server.requires_approval') }}</label>
                    @endif
                    <button type="submit" class="btn btn-sm btn-default">{{ exmtrans('common.save') }}</button>
                </form>
            @elseif (!$held && !isset($retained_until) && $can_download)
                <p class="exm-fs-muted" style="margin:0">{{ exmtrans('file_server.rules_none') }}</p>
            @endif
        </div>
    </div>

    <div class="exm-fs-section box card">
        <div class="box-header"><h4><i class="fa fa-comments-o" aria-hidden="true"></i> {{ exmtrans('file_server.comments') }}</h4>
            <button type="button" class="btn btn-xs btn-default pull-right" data-fs-follow="{{ $follow_url }}" aria-pressed="{{ $following ? 'true' : 'false' }}">
                <i class="fa {{ $following ? 'fa-bell' : 'fa-bell-o' }}" aria-hidden="true"></i> {{ $following ? exmtrans('file_server.following') : exmtrans('file_server.follow') }}</button>
        </div>
        <div class="box-body">
            @forelse ($comments as $c)
                <div style="border-bottom:1px solid rgba(0,0,0,.08);padding:6px 0">
                    <div class="exm-fs-muted">{{ $c['user'] }} {{ optional($c['created_at'])->format('Y/m/d H:i') }}
                        @if ($c['delete_url'])
                            <button type="button" class="btn btn-xs btn-link" data-fs-comment-delete="{{ $c['delete_url'] }}">{{ exmtrans('file_server.delete') }}</button>
                        @endif
                    </div>
                    <div style="overflow-wrap:anywhere;white-space:pre-wrap">{{ $c['body'] }}</div>
                </div>
            @empty
                <p class="exm-fs-muted">{{ exmtrans('file_server.comments_none') }}</p>
            @endforelse
            <form data-fs-comment-form style="margin-top:8px">
                <textarea name="body" class="form-control input-sm" rows="2" maxlength="5000" required placeholder="{{ exmtrans('file_server.comment_placeholder') }}" aria-label="{{ exmtrans('file_server.comments') }}"></textarea>
                <button type="submit" class="btn btn-sm btn-primary" style="margin-top:6px">{{ exmtrans('file_server.comment_add') }}</button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-governance');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    root.addEventListener('click', function (e) {
        var decide = e.target.closest('[data-fs-decide]');
        if (decide) {
            var note = root.querySelector('[data-fs-approval-comment]');
            Fs.post(decide.getAttribute('data-fs-decide'), { comment: note ? note.value : '' }).then(Fs.done, Fs.fail);
            return;
        }
        var follow = e.target.closest('[data-fs-follow]');
        if (follow) {
            Fs.post(follow.getAttribute('data-fs-follow'), {}).then(Fs.done, Fs.fail);
            return;
        }
        var del = e.target.closest('[data-fs-comment-delete]');
        if (del && confirm(@json(exmtrans('file_server.confirm_comment_delete')))) {
            Fs.post(del.getAttribute('data-fs-comment-delete'), {}).then(Fs.done, Fs.fail);
        }
    });
    var rules = root.querySelector('[data-fs-rules-form]');
    if (rules) {
        rules.addEventListener('submit', function (e) {
            e.preventDefault();
            Fs.post(@json($rules_url), new FormData(rules), true).then(Fs.done, Fs.fail);
        });
    }
    var comment = root.querySelector('[data-fs-comment-form]');
    if (comment) {
        comment.addEventListener('submit', function (e) {
            e.preventDefault();
            Fs.post(@json($comment_url), new FormData(comment), true).then(Fs.done, Fs.fail);
        });
    }
})();
</script>

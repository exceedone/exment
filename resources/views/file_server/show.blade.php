{{-- File server: a file (preview, versions, check out, permissions) or a folder (permissions). Data from FileServerController::showNode. --}}
@include('exment::file_server._assets')
@php
    $canEdit = $level >= \Exceedone\Exment\Services\FileServer\Level::EDIT;
    $canManage = $level >= \Exceedone\Exment\Services\FileServer\Level::MANAGE;
    $lockedByOther = $checked_out_by !== null && !$checked_out_by_me;
@endphp
<div class="exm-fs" id="exm-fs-node" data-url="{{ $node_url }}" data-parent-url="{{ $parent_url }}">
    <div class="exm-fs-breadcrumb">
        <a href="{{ admin_url('file_server') }}"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('file_server.header') }}</a>
        @foreach ($breadcrumbs as $i => $crumb)
            @if ($i === count($breadcrumbs) - 1)
                <span>{{ $crumb['name'] }}</span>
            @else
                <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
            @endif
        @endforeach
    </div>

    <div class="exm-fs-toolbar">
        @if ($node->isFolder())
            <a class="btn btn-sm btn-default" href="{{ $base_url }}/folder/{{ $node->id }}"><i class="fa fa-folder-open" aria-hidden="true"></i> {{ exmtrans('file_server.open') }}</a>
        @endif
        @if ($can_download)
            <a class="btn btn-sm btn-primary" href="{{ $node_url }}/download"><i class="fa fa-download" aria-hidden="true"></i> {{ exmtrans('file_server.download') }}</a>
        @endif
        @foreach ($openers as $opener)
            <a class="btn btn-sm btn-default" href="{{ $opener['url'] }}" target="_blank" rel="noopener"><i class="fa {{ $opener['icon'] ?? 'fa-external-link' }}" aria-hidden="true"></i> {{ $opener['label'] }}</a>
        @endforeach
        <button type="button" class="btn btn-sm btn-default" data-fs-copy="{{ $node_url }}" title="{{ exmtrans('file_server.copy_internal_link_help') }}"><i class="fa fa-link" aria-hidden="true"></i> {{ exmtrans('file_server.copy_internal_link') }}</button>
        <button type="button" class="btn btn-sm btn-default" data-fs-action="favorite"><i class="fa {{ $favorite ? 'fa-star' : 'fa-star-o' }}" aria-hidden="true"></i> {{ exmtrans('file_server.favorite') }}</button>
        @if ($node->isFile() && $canEdit)
            @if ($checked_out_by === null)
                <button type="button" class="btn btn-sm btn-default" data-fs-action="checkout"><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('file_server.checkout') }}</button>
            @elseif ($checked_out_by_me)
                <button type="button" class="btn btn-sm btn-default" data-fs-action="checkin"><i class="fa fa-unlock" aria-hidden="true"></i> {{ exmtrans('file_server.checkin') }}</button>
            @elseif ($canManage)
                <button type="button" class="btn btn-sm btn-warning" data-fs-action="discard_checkout"><i class="fa fa-unlock" aria-hidden="true"></i> {{ exmtrans('file_server.discard_checkout') }}</button>
            @endif
        @endif
        @if ($canEdit && !$lockedByOther)
            <button type="button" class="btn btn-sm btn-default" data-fs-action="rename"><i class="fa fa-pencil" aria-hidden="true"></i> {{ exmtrans('file_server.rename') }}</button>
            <button type="button" class="btn btn-sm btn-danger" data-fs-action="trash"><i class="fa fa-trash" aria-hidden="true"></i> {{ exmtrans('file_server.delete') }}</button>
        @endif
    </div>

    @if ($checked_out_by !== null)
        <div class="alert alert-warning" style="padding:8px 12px"><i class="fa fa-lock" aria-hidden="true"></i>
            {{ exmtrans('file_server.checked_out_by', ['user' => $checked_out_by, 'at' => optional($node->checked_out_at)->format('Y/m/d H:i')]) }}</div>
    @endif

    <div class="row">
        <div class="col-md-8">
            @if ($node->isFile())
                <div class="exm-fs-section">
                    <div class="exm-fs-preview">
                        @include('exment::file_server._watermark')
                        @switch($kind)
                            @case('image')
                                <img src="{{ $node_url }}/content" alt="{{ $node->name }}">
                                @break
                            @case('video')
                                <video src="{{ $node_url }}/content" controls preload="metadata"></video>
                                @break
                            @case('audio')
                                <audio src="{{ $node_url }}/content" controls preload="metadata"></audio>
                                @break
                            @case('pdf')
                                <iframe src="{{ $node_url }}/content" title="{{ $node->name }}"></iframe>
                                @break
                            @case('text')
                                @if ($text !== null)
                                    <pre>{{ $text }}</pre>
                                @else
                                    <p class="exm-fs-muted">{{ exmtrans('file_server.preview_too_large') }}</p>
                                @endif
                                @break
                            @case('office')
                                @php $embed = collect($openers)->first(function ($o) { return !empty($o['embed_url']); }); @endphp
                                @if ($has_pdf_preview && isset($embed))
                                    {{-- both: the PDF of LibreOffice, and the viewer of a plugin (made when its tab is opened) --}}
                                    <div style="width:100%" data-fs-preview-tabs>
                                        <div class="btn-group btn-group-sm" role="tablist" style="margin:6px">
                                            <button type="button" class="btn btn-primary" role="tab" aria-selected="true" data-fs-tab="pdf">{{ exmtrans('file_server.preview_tab_pdf') }}</button>
                                            <button type="button" class="btn btn-default" role="tab" aria-selected="false" data-fs-tab="embed">{{ $embed['label'] }}（{{ exmtrans('file_server.preview_tab_embed') }}）</button>
                                        </div>
                                        <iframe src="{{ $node_url }}/content?rendition=pdf" title="{{ $node->name }}" data-fs-pane="pdf"></iframe>
                                        <iframe data-src="{{ $embed['embed_url'] }}" title="{{ $node->name }}" data-fs-pane="embed" hidden allow="clipboard-read; clipboard-write"></iframe>
                                    </div>
                                @elseif ($has_pdf_preview)
                                    <iframe src="{{ $node_url }}/content?rendition=pdf" title="{{ $node->name }}"></iframe>
                                @elseif ($rendition_status === null)
                                    <p class="exm-fs-muted">{{ exmtrans('file_server.preview_making') }}</p>
                                @else
                                    @if (isset($embed))
                                        {{-- the viewer of a plugin (read only), as the server makes no PDF --}}
                                        <iframe src="{{ $embed['embed_url'] }}" title="{{ $node->name }}" allow="clipboard-read; clipboard-write"></iframe>
                                    @else
                                        <div style="width:100%;padding:12px">
                                            @if (count($openers) > 0)
                                                <p>
                                                    @foreach ($openers as $opener)
                                                        <a class="btn btn-primary" href="{{ $opener['url'] }}" target="_blank" rel="noopener"><i class="fa {{ $opener['icon'] ?? 'fa-external-link' }}" aria-hidden="true"></i> {{ $opener['label'] }}</a>
                                                    @endforeach
                                                </p>
                                            @endif
                                            @if (isset($office_text) && $office_text !== '')
                                                <p class="exm-fs-muted" style="margin-bottom:4px">{{ exmtrans('file_server.preview_office_text') }}</p>
                                                <pre style="max-height:60vh">{{ $office_text }}</pre>
                                            @else
                                                <p class="exm-fs-muted">{{ count($openers) > 0 ? exmtrans('file_server.preview_office_open') : exmtrans('file_server.preview_office_none') }}</p>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                                @break
                            @default
                                <p class="exm-fs-muted">{{ exmtrans('file_server.preview_none') }}</p>
                        @endswitch
                    </div>
                </div>

                <div class="exm-fs-section box card">
                    <div class="box-header"><h4><i class="fa fa-history" aria-hidden="true"></i> {{ exmtrans('file_server.versions') }}</h4></div>
                    <div class="box-body">
                        @if ($canEdit && !$lockedByOther)
                            <form data-fs-version-form class="form-inline" style="margin-bottom:10px">
                                <input type="file" name="file" required class="form-control input-sm" aria-label="{{ exmtrans('file_server.upload_version') }}">
                                <input type="text" name="comment" class="form-control input-sm" placeholder="{{ exmtrans('file_server.version_comment') }}" maxlength="1000">
                                @if ($checked_out_by_me)
                                    <label style="font-weight:normal"><input type="checkbox" name="checkin" value="1" checked> {{ exmtrans('file_server.checkin_after') }}</label>
                                @endif
                                <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-upload" aria-hidden="true"></i> {{ exmtrans('file_server.upload_version') }}</button>
                            </form>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-condensed exm-fs-table">
                                <thead><tr><th>{{ exmtrans('file_server.version') }}</th><th>{{ exmtrans('file_server.updated_at') }}</th><th>{{ exmtrans('file_server.size') }}</th><th>{{ exmtrans('file_server.version_comment') }}</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($versions as $v)
                                        <tr>
                                            <td>v{{ $v['no'] }} @if ($v['current'])<span class="exm-fs-badge">{{ exmtrans('file_server.current') }}</span>@endif</td>
                                            <td class="exm-fs-muted">{{ optional($v['created_at'])->format('Y/m/d H:i') }} {{ $v['user'] }}</td>
                                            <td class="exm-fs-muted"><span data-fs-size="{{ $v['size'] }}"></span></td>
                                            <td style="overflow-wrap:anywhere">{{ $v['comment'] }}</td>
                                            <td class="exm-fs-actions">
                                                @if ($can_download)
                                                    <a class="btn btn-xs btn-default" href="{{ $v['download_url'] }}" title="{{ exmtrans('file_server.download') }}"><i class="fa fa-download" aria-hidden="true"></i></a>
                                                @endif
                                                @if (!$v['current'] && $canEdit && !$lockedByOther)
                                                    <button type="button" class="btn btn-xs btn-default" data-fs-restore="{{ $v['restore_url'] }}" title="{{ exmtrans('file_server.restore_version') }}"><i class="fa fa-undo" aria-hidden="true"></i></button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="exm-fs-section box card">
                <div class="box-body">
                    <dl style="margin:0">
                        <dt>{{ exmtrans('file_server.name') }}</dt><dd style="overflow-wrap:anywhere">{{ $node->name }}</dd>
                        @if ($node->isFile())
                            <dt>{{ exmtrans('file_server.size') }}</dt><dd><span data-fs-size="{{ $node->size }}"></span></dd>
                            <dt>{{ exmtrans('file_server.mime_type') }}</dt><dd class="exm-fs-muted">{{ $node->mime_type }}</dd>
                        @endif
                        <dt>{{ exmtrans('file_server.created_at') }}</dt><dd>{{ optional($node->created_at)->format('Y/m/d H:i') }} {{ $created_user }}</dd>
                        <dt>{{ exmtrans('file_server.updated_at') }}</dt><dd>{{ optional($node->updated_at)->format('Y/m/d H:i') }} {{ $updated_user }}</dd>
                        <dt>{{ exmtrans('file_server.your_level') }}</dt><dd>{{ $level_label }}</dd>
                        @if (isset($text_status))
                            <dt>{{ exmtrans('file_server.text_status') }}</dt><dd class="exm-fs-muted">{{ exmtrans('file_server.text_status_' . $text_status) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @include('exment::file_server._metadata_box')

            @include('exment::file_server._governance_box')

            <div class="exm-fs-section box card">
                <div class="box-header"><h4><i class="fa fa-key" aria-hidden="true"></i> {{ exmtrans('file_server.permissions') }}</h4></div>
                <div class="box-body">
                    <p class="exm-fs-muted">{{ $node->has_unique_permissions ? exmtrans('file_server.permissions_unique') : exmtrans('file_server.permissions_inherited') }}</p>
                    @if ($canManage && $node->has_unique_permissions)
                        @include('exment::file_server._permissions_editor', ['editor_id' => 'exm-fs-node-perms', 'entries' => $permissions])
                        <div style="margin-top:8px">
                            <button type="button" class="btn btn-sm btn-primary" data-fs-action="save_permissions">{{ exmtrans('common.save') }}</button>
                            <button type="button" class="btn btn-sm btn-default" data-fs-action="inherit">{{ exmtrans('file_server.inherit_permissions') }}</button>
                        </div>
                    @else
                        <ul style="padding-left:18px;margin:0 0 8px">
                            @foreach ($permissions as $p)
                                <li>{{ $p['name'] }} <span class="exm-fs-muted">({{ $p['level_label'] }})</span></li>
                            @endforeach
                        </ul>
                        @if ($canManage)
                            <button type="button" class="btn btn-sm btn-default" data-fs-action="break">{{ exmtrans('file_server.break_permissions') }}</button>
                        @endif
                    @endif
                </div>
            </div>

            @if ($can_share || $can_request || count($links) > 0)
            <div class="exm-fs-section box card">
                <div class="box-header"><h4><i class="fa fa-share-alt" aria-hidden="true"></i> {{ exmtrans('file_server.share_links') }}</h4></div>
                <div class="box-body">
                    @foreach ($links as $l)
                        <div style="border-bottom:1px solid rgba(0,0,0,.08);padding:6px 0;{{ $l['active'] ? '' : 'opacity:.55' }}">
                            <div><strong>{{ $l['kind'] === 'request' ? exmtrans('file_server.share_kind_request') : exmtrans('file_server.share_kind_share') }}</strong>
                                <span class="exm-fs-badge">{{ $l['state'] }}</span>
                                @if ($l['password'])<span class="exm-fs-badge"><i class="fa fa-lock" aria-hidden="true"></i></span>@endif
                                @if ($l['kind'] === 'share' && !$l['allow_download'])<span class="exm-fs-badge">{{ exmtrans('file_server.share_preview_only') }}</span>@endif
                            </div>
                            @if ($l['active'])
                                <div style="display:flex;gap:4px;margin:4px 0">
                                    <input type="text" class="form-control input-sm" readonly value="{{ $l['url'] }}" aria-label="URL">
                                    <button type="button" class="btn btn-sm btn-default" data-fs-copy="{{ $l['url'] }}" title="{{ exmtrans('file_server.copy') }}"><i class="fa fa-clipboard" aria-hidden="true"></i></button>
                                </div>
                            @endif
                            <div class="exm-fs-muted">
                                {{ exmtrans('file_server.share_expires') }}: {{ $l['expires_at'] ? $l['expires_at']->format('Y/m/d') : exmtrans('file_server.share_no_expiry') }}
                                / {{ exmtrans('file_server.share_access') }}: {{ $l['access'] }}
                                / {{ $l['user'] }} {{ optional($l['created_at'])->format('Y/m/d') }}
                                @if ($l['active'])
                                    <button type="button" class="btn btn-xs btn-link" data-fs-revoke="{{ $l['revoke_url'] }}">{{ exmtrans('file_server.share_revoke') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($can_share || $can_request)
                        <form data-fs-link-form style="margin-top:10px">
                            <div class="form-group" style="margin-bottom:6px">
                                <select name="kind" class="form-control input-sm" aria-label="{{ exmtrans('file_server.share_kind') }}">
                                    @if ($can_share)<option value="share">{{ exmtrans('file_server.share_kind_share') }}</option>@endif
                                    @if ($can_request)<option value="request">{{ exmtrans('file_server.share_kind_request') }}</option>@endif
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:6px">
                                <label style="font-weight:normal;font-size:.85em">{{ exmtrans('file_server.share_expires') }}@if ($share_max_days > 0) ({{ exmtrans('file_server.share_max_days', ['days' => $share_max_days]) }})@endif</label>
                                <input type="date" name="expires_at" class="form-control input-sm" min="{{ now()->format('Y-m-d') }}"
                                       @if ($share_max_days > 0) required max="{{ now()->addDays($share_max_days)->format('Y-m-d') }}" value="{{ now()->addDays(min(7, $share_max_days))->format('Y-m-d') }}" @else value="{{ now()->addDays(7)->format('Y-m-d') }}" @endif>
                            </div>
                            <div class="form-group" style="margin-bottom:6px">
                                <input type="password" name="password" class="form-control input-sm" autocomplete="new-password" placeholder="{{ exmtrans('file_server.share_password_placeholder') }}" aria-label="{{ exmtrans('file_server.share_password_placeholder') }}">
                            </div>
                            <div class="form-group" style="margin-bottom:6px">
                                <input type="number" name="max_access" min="1" class="form-control input-sm" placeholder="{{ exmtrans('file_server.share_max_access_placeholder') }}" aria-label="{{ exmtrans('file_server.share_max_access_placeholder') }}">
                            </div>
                            <div class="form-group" data-fs-only="share" style="margin-bottom:6px">
                                <label style="font-weight:normal"><input type="checkbox" name="allow_download" value="1" checked> {{ exmtrans('file_server.share_allow_download') }}</label>
                            </div>
                            <div class="form-group" data-fs-only="request" style="margin-bottom:6px" hidden>
                                <textarea name="message" class="form-control input-sm" rows="2" maxlength="1000" placeholder="{{ exmtrans('file_server.share_message_placeholder') }}"></textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-link" aria-hidden="true"></i> {{ exmtrans('file_server.share_create') }}</button>
                        </form>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('exm-fs-node');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    var url = root.dataset.url;
    var t = {!! json_encode([
        'new_name' => exmtrans('file_server.prompt_new_name'),
        'confirm_trash' => exmtrans('file_server.confirm_trash'),
        'confirm_restore' => exmtrans('file_server.confirm_restore_version'),
        'confirm_break' => exmtrans('file_server.confirm_break_permissions'),
        'confirm_inherit' => exmtrans('file_server.confirm_inherit_permissions'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
    var name = @json($node->name);

    root.querySelectorAll('[data-fs-size]').forEach(function (el) { el.textContent = Fs.size(el.getAttribute('data-fs-size')); });

    root.addEventListener('click', function (e) {
        var restore = e.target.closest('[data-fs-restore]');
        if (restore) {
            if (confirm(t.confirm_restore)) { Fs.post(restore.getAttribute('data-fs-restore'), {}).then(Fs.done, Fs.fail); }
            return;
        }
        var btn = e.target.closest('[data-fs-action]');
        if (!btn) { return; }
        var action = btn.getAttribute('data-fs-action');
        switch (action) {
            case 'favorite':
            case 'checkout':
            case 'checkin':
            case 'discard_checkout':
                Fs.post(url + '/' + action, {}).then(Fs.done, Fs.fail);
                break;
            case 'rename':
                var n = prompt(t.new_name, name);
                if (n && n !== name) { Fs.post(url + '/rename', { name: n }).then(Fs.done, Fs.fail); }
                break;
            case 'trash':
                if (confirm(t.confirm_trash)) {
                    Fs.post(url + '/trash', {}).then(function (json) { Fs.notify(json.message); location.href = root.dataset.parentUrl; }, Fs.fail);
                }
                break;
            case 'break':
                if (confirm(t.confirm_break)) { Fs.post(url + '/permissions', { mode: 'break' }).then(Fs.done, Fs.fail); }
                break;
            case 'inherit':
                if (confirm(t.confirm_inherit)) { Fs.post(url + '/permissions', { mode: 'inherit' }).then(Fs.done, Fs.fail); }
                break;
            case 'save_permissions':
                var fd = new FormData();
                fd.append('mode', 'set');
                document.getElementById('exm-fs-node-perms').fsAppendTo(fd);
                Fs.post(url + '/permissions', fd, true).then(Fs.done, Fs.fail);
                break;
        }
    });

    function copyText(text) {
        var done = function () { Fs.notify(@json(exmtrans('file_server.copied'))); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { prompt('URL', text); });
        } else {
            prompt('URL', text);
        }
    }
    root.addEventListener('click', function (e) {
        var c = e.target.closest('[data-fs-copy]');
        if (c) { copyText(c.getAttribute('data-fs-copy')); return; }
        var r = e.target.closest('[data-fs-revoke]');
        if (r && confirm(@json(exmtrans('file_server.confirm_share_revoke')))) {
            Fs.post(r.getAttribute('data-fs-revoke'), {}).then(Fs.done, Fs.fail);
        }
    });
    var linkForm = root.querySelector('[data-fs-link-form]');
    if (linkForm) {
        var kindSelect = linkForm.querySelector('[name="kind"]');
        var toggle = function () {
            linkForm.querySelectorAll('[data-fs-only]').forEach(function (el) { el.hidden = el.getAttribute('data-fs-only') !== kindSelect.value; });
        };
        kindSelect.addEventListener('change', toggle);
        toggle();
        linkForm.addEventListener('submit', function (e) {
            e.preventDefault();
            Fs.post(url + '/links', new FormData(linkForm), true).then(function (json) {
                copyText(json.url);
                Fs.done(json);
            }, Fs.fail);
        });
    }

    var tabs = root.querySelector('[data-fs-preview-tabs]');
    if (tabs) {
        tabs.addEventListener('click', function (e) {
            var b = e.target.closest('[data-fs-tab]');
            if (!b) { return; }
            var key = b.getAttribute('data-fs-tab');
            tabs.querySelectorAll('[data-fs-tab]').forEach(function (x) {
                var on = x === b;
                x.classList.toggle('btn-primary', on);
                x.classList.toggle('btn-default', !on);
                x.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            tabs.querySelectorAll('[data-fs-pane]').forEach(function (pane) {
                var on = pane.getAttribute('data-fs-pane') === key;
                if (on && !pane.getAttribute('src') && pane.getAttribute('data-src')) { pane.setAttribute('src', pane.getAttribute('data-src')); }
                pane.hidden = !on;
            });
        });
    }

    var form = root.querySelector('[data-fs-version-form]');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            Fs.post(url + '/version', fd, true).then(Fs.done, Fs.fail);
        });
    }
})();
</script>

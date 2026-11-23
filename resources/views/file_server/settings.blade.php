{{-- File server: a new workspace, or the settings and members of one. Data from FileServerController::create / settings. --}}
@include('exment::file_server._assets')
<div class="exm-fs" id="exm-fs-settings" data-action="{{ $action }}" data-delete="{{ $delete_url }}">
    <div class="box card">
        <form data-fs-settings-form class="form-horizontal">
        <div class="box-body">
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="exm-fs-name">{{ exmtrans('file_server.workspace_name') }}</label>
                    <div class="col-sm-8"><input id="exm-fs-name" type="text" name="name" class="form-control" required maxlength="256" value="{{ optional($workspace)->name }}"></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="exm-fs-desc">{{ exmtrans('file_server.workspace_description') }}</label>
                    <div class="col-sm-8"><textarea id="exm-fs-desc" name="description" class="form-control" rows="2">{{ optional($workspace)->description }}</textarea></div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="exm-fs-quota">{{ exmtrans('file_server.quota_gb') }}</label>
                    <div class="col-sm-3">
                        <input id="exm-fs-quota" type="number" name="quota_gb" class="form-control" min="0" step="0.1"
                               value="{{ isset($workspace) && isset($workspace->quota_bytes) ? round($workspace->quota_bytes / 1073741824, 2) : '' }}">
                    </div>
                    <div class="col-sm-5 help-block">{{ exmtrans('file_server.quota_help') }}
                        @if (isset($workspace)) {{ exmtrans('file_server.used') }}: <span data-fs-size="{{ $used_bytes }}"></span>@endif</div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="exm-fs-trash">{{ exmtrans('file_server.trash_days') }}</label>
                    <div class="col-sm-3"><input id="exm-fs-trash" type="number" name="trash_days" class="form-control" min="0" max="3650" value="{{ isset($workspace) ? $workspace->trash_days : 30 }}"></div>
                    <div class="col-sm-5 help-block">{{ exmtrans('file_server.trash_days_help') }}</div>
                </div>
                <div class="form-group">
                    <label class="col-sm-2 control-label" for="exm-fs-ver">{{ exmtrans('file_server.version_limit') }}</label>
                    <div class="col-sm-3"><input id="exm-fs-ver" type="number" name="version_limit" class="form-control" min="1" max="1000" value="{{ isset($workspace) ? $workspace->version_limit : 50 }}"></div>
                </div>
                @if (isset($workspace))
                    {{-- phase 5 (ISS00379); the retention and the hold: the system administrators (Governance::canSetHold) --}}
                    @php $canHold = \Exceedone\Exment\Services\FileServer\Governance::canSetHold(\Exceedone\Exment\Services\FileServer\Access::current()); @endphp
                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="exm-fs-ret">{{ exmtrans('file_server.retention_days') }}</label>
                        <div class="col-sm-3"><input id="exm-fs-ret" type="number" name="retention_days" class="form-control" min="0" max="36500" value="{{ $workspace->retention_days }}" @disabled(!$canHold)></div>
                        <div class="col-sm-5">
                            <select name="retention_action" class="form-control" aria-label="{{ exmtrans('file_server.retention_action') }}" @disabled(!$canHold)>
                                <option value="keep" @selected($workspace->retention_action !== 'delete')>{{ exmtrans('file_server.retention_keep') }}</option>
                                <option value="delete" @selected($workspace->retention_action === 'delete')>{{ exmtrans('file_server.retention_delete') }}</option>
                            </select>
                            <p class="help-block">{{ exmtrans('file_server.retention_help') }}@if (!$canHold) {{ exmtrans('file_server.hold_admin_only') }}@endif</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="exm-fs-dl">{{ exmtrans('file_server.download_level') }}</label>
                        <div class="col-sm-3">
                            <select id="exm-fs-dl" name="download_level" class="form-control">
                                @foreach ([1, 2, 3, 4] as $lv)
                                    <option value="{{ $lv }}" @selected(intval($workspace->download_level ?: 1) === $lv)>{{ \Exceedone\Exment\Services\FileServer\Level::label($lv) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-5 help-block">{{ exmtrans('file_server.download_level_help') }}</div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-8">
                            <label style="font-weight:normal;display:block"><input type="checkbox" name="watermark" value="1" @checked($workspace->watermark)> {{ exmtrans('file_server.watermark') }}</label>
                            <label style="font-weight:normal;display:block"><input type="checkbox" name="legal_hold" value="1" @checked($workspace->legal_hold) @disabled(!$canHold)> {{ exmtrans('file_server.legal_hold_workspace') }}</label>
                            @if (isset($audit_url))
                                <a href="{{ $audit_url }}"><i class="fa fa-history" aria-hidden="true"></i> {{ exmtrans('file_server.audit') }}</a>
                            @endif
                        </div>
                    </div>
                @endif
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ exmtrans('file_server.members') }}</label>
                    <div class="col-sm-8">
                        <p class="help-block" style="margin-top:0">{{ isset($workspace) ? exmtrans('file_server.members_help') : exmtrans('file_server.members_help_new') }}</p>
                        @include('exment::file_server._permissions_editor', ['editor_id' => 'exm-fs-members', 'entries' => $members])
                    </div>
                </div>
        </div>
        {{-- the buttons in the footer of the box, as the other forms of the admin pages --}}
        <div class="box-footer">
            <div class="col-sm-offset-2 col-sm-8">
                <a href="{{ $back_url }}" class="btn btn-default">{{ exmtrans('file_server.cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ exmtrans('common.save') }}</button>
                @if ($delete_url)
                    <button type="button" class="btn btn-danger pull-right" data-fs-delete-workspace>{{ exmtrans('file_server.delete_workspace') }}</button>
                @endif
            </div>
        </div>
        </form>
    </div>
    @if (isset($fields_url))
        @include('exment::file_server._fields_editor')
    @endif
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-settings');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    root.querySelectorAll('[data-fs-size]').forEach(function (el) { el.textContent = Fs.size(el.getAttribute('data-fs-size')); });
    var form = root.querySelector('[data-fs-settings-form]');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        document.getElementById('exm-fs-members').fsAppendTo(fd);
        Fs.post(root.dataset.action, fd, true).then(Fs.done, Fs.fail);
    });
    var del = root.querySelector('[data-fs-delete-workspace]');
    if (del) {
        del.addEventListener('click', function () {
            Fs.confirm(@json(exmtrans('file_server.confirm_delete_workspace'))).then(function (ok) {
                if (ok) { Fs.send('DELETE', root.dataset.delete).then(Fs.done, Fs.fail); }
            });
        });
    }
})();
</script>

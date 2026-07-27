<style>
    .meili-quickbar { background:#fff; border:1px solid #e4e8ee; border-radius:3px; padding:8px 10px; margin-bottom:10px; display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
    .meili-quickbar .label-q { color:#999; font-size:12px; margin-right:4px; }
    .meili-quickbar .qf { display:inline-block; font-size:12px; border:1px solid #d6dbe2; border-radius:14px; padding:3px 11px; color:#445; cursor:pointer; background:#fafbfc; text-decoration:none; }
    .meili-quickbar .qf:hover { background:#eef2f7; }
    .meili-quickbar .qf.on { background:#3c8dbc; color:#fff; border-color:#3c8dbc; }
    .meili-quickbar .qf .fa { margin-right:4px; }
    .meili-quickbar .qf .qf-kw { opacity:.7; margin-left:3px; font-size:11px; }
    .meili-quickbar .qf .qf-del { margin-left:6px; margin-right:0; color:#999; }
    .meili-quickbar .qf.on .qf-del { color:#d9edf7; }
    .meili-quickbar .qf-save { margin-left:auto; color:#f39c12; border-color:#f7d9a0; }
    #meili-ss-targets { margin-top:8px; }
</style>
<div class="meili-quickbar">
    <span class="label-q"><i class="fa fa-bolt"></i> {{ exmtrans('search.saved_search') }}:</span>
    @forelse ($savedSearches as $ss)
        <a href="{{ $ss['apply_url'] }}" class="qf{{ $ss['active'] ? ' on' : '' }}">
            <i class="fa fa-bookmark-o"></i>{{ $ss['name'] }}@if ($ss['query'] !== '')<span class="qf-kw">"{{ $ss['query'] }}"</span>@endif @if ($ss['can_manage'])<i class="fa fa-times qf-del meili-ss-delete" data-id="{{ $ss['id'] }}" title="{{ trans('admin.delete') }}"></i>@endif
        </a>
    @empty
        <span class="label-q">{{ exmtrans('search.no_saved_search') }}</span>
    @endforelse
    <span class="qf qf-save" id="meili-ss-save"><i class="fa fa-floppy-o"></i>{{ exmtrans('search.save_current') }}</span>
    </div>

{{-- Modal to save a condition: name + include keyword? + share scope (+ target role/organization) --}}
<div class="modal fade" id="meili-ss-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">{{ exmtrans('search.save_current') }}</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>{{ exmtrans('search.saved_prompt_name') }}</label>
                    <input type="text" class="form-control" id="meili-ss-name" maxlength="100">
                </div>
                <div class="form-group" id="meili-ss-withquery-wrap">
                    <label><input type="checkbox" id="meili-ss-withquery" checked> {{ exmtrans('search.saved_with_keyword') }}</label>
                </div>
                <div class="form-group">
                    <label>{{ exmtrans('search.saved_share_scope') }}</label>
                    <select class="form-control" id="meili-ss-sharetype">
                        <option value="personal">{{ exmtrans('search.share_personal') }}</option>
                        <option value="all">{{ exmtrans('search.share_all') }}</option>
                        @if (!empty($roleGroups))<option value="role_group">{{ exmtrans('search.share_role_group') }}</option>@endif
                        @if (!empty($organizations))<option value="organization">{{ exmtrans('search.share_organization') }}</option>@endif
                    </select>
                </div>
                <div class="form-group" id="meili-ss-targets" style="display:none;">
                    <label id="meili-ss-targets-label"></label>
                    <select class="form-control" id="meili-ss-targets-select" multiple size="6" style="height:auto;">
                        <optgroup label="role_group" id="meili-ss-opt-role">
                            @foreach ($roleGroups as $rg)<option value="{{ $rg['id'] }}" data-kind="role_group">{{ $rg['name'] }}</option>@endforeach
                        </optgroup>
                        <optgroup label="organization" id="meili-ss-opt-org">
                            @foreach ($organizations as $org)<option value="{{ $org['id'] }}" data-kind="organization">{{ $org['name'] }}</option>@endforeach
                        </optgroup>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('admin.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="meili-ss-submit">{{ trans('admin.save') }}</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    var $modal = $('#meili-ss-modal');
    var curQuery = new URLSearchParams(window.location.search).get('query') || '';

    $('#meili-ss-save').on('click', function () {
        $('#meili-ss-name').val('');
        $('#meili-ss-sharetype').val('personal');
        $('#meili-ss-targets').hide();
        $('#meili-ss-targets-select').val([]);
        // No current keyword -> hide the "include keyword" option.
        $('#meili-ss-withquery-wrap').toggle(curQuery !== '');
        $('#meili-ss-withquery').prop('checked', curQuery !== '');
        $modal.modal('show');
    });

    // Change scope -> show/hide the target list and filter the correct group (role vs organization).
    $('#meili-ss-sharetype').on('change', function () {
        var t = $(this).val();
        var isTarget = (t === 'role_group' || t === 'organization');
        $('#meili-ss-targets').toggle(isTarget);
        if (isTarget) {
            $('#meili-ss-opt-role').toggle(t === 'role_group');
            $('#meili-ss-opt-org').toggle(t === 'organization');
            $('#meili-ss-targets-select').val([]);
            $('#meili-ss-targets-label').text(
                t === 'role_group'
                    ? @json(exmtrans('search.share_role_group'))
                    : @json(exmtrans('search.share_organization'))
            );
        }
    });

    $('#meili-ss-submit').on('click', function () {
        var name = $('#meili-ss-name').val().trim();
        if (!name) { $('#meili-ss-name').focus(); return; }
        var shareType = $('#meili-ss-sharetype').val();
        var withQuery = (curQuery !== '' && $('#meili-ss-withquery').is(':checked')) ? 1 : 0;

        var targets = [];
        if (shareType === 'role_group' || shareType === 'organization') {
            targets = $('#meili-ss-targets-select').val() || [];
            if (targets.length === 0) { $('#meili-ss-targets-select').focus(); return; }
        }

        // POST the current query string as-is (keep the nested array form range[...]).
        var qs = window.location.search.replace(/^\?/, '');
        var payload = qs
            + '&_token=' + encodeURIComponent(LA.token)
            + '&name=' + encodeURIComponent(name)
            + '&with_query=' + withQuery
            + '&share_type=' + encodeURIComponent(shareType);
        targets.forEach(function (id) { payload += '&share_targets[]=' + encodeURIComponent(id); });

        $('#meili-ss-submit').prop('disabled', true);
        $.post(@json(admin_url('search/saved')), payload)
            .done(function () { window.location.reload(); })
            .fail(function () { $('#meili-ss-submit').prop('disabled', false); });
    });

    $('.meili-ss-delete').on('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (!window.confirm(@json(trans('admin.deleting')))) { return; }
        $.post(@json(admin_url('search/saved')) + '/' + $(this).data('id'), {
            _method: 'DELETE',
            _token: LA.token
        }).done(function () { window.location.reload(); });
    });
});
</script>

{{-- The easy setup of a web form (ISS02942): three steps on one page, the rest under "More settings" --}}
<form method="post" action="{{ $action }}" class="public-form-easy" id="public-form-easy">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <p>{{ exmtrans('custom_form_public.easy.explain') }}</p>
    @if(!$can_add)
    <div class="alert alert-warning">{{ exmtrans('custom_form_public.easy.warning_permission') }}</div>
    @endif

    {{-- 1. the fields --}}
    <h4 style="margin-top:16px"><span class="badge bg-primary">1</span> {{ exmtrans('custom_form_public.easy.step_fields') }}</h4>
    <p class="text-muted">{{ exmtrans('custom_form_public.easy.step_fields_help') }}</p>
    <table class="table table-sm" style="width:100%;max-width:760px;table-layout:auto">
        <thead>
            <tr>
                <th style="width:90px">{{ exmtrans('custom_form_public.easy.col_show') }}</th>
                <th style="width:110px">{{ exmtrans('custom_form_public.easy.col_required') }}</th>
                <th>{{ exmtrans('custom_form_public.easy.col_field') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($columns as $columnName => $column)
            <tr data-column="{{ $columnName }}">
                <td><input type="checkbox" name="columns[]" value="{{ $columnName }}" class="easy-show" @if($column['checked']) checked @endif @if($column['table_required']) checked onclick="return false;" @endif></td>
                <td><input type="checkbox" name="required[]" value="{{ $columnName }}" class="easy-required" @if($column['table_required']) checked onclick="return false;" @endif></td>
                <td style="white-space:normal;word-break:break-word">
                    {{ $column['label'] }}
                    @if($column['table_required'])<span class="text-muted small">（{{ exmtrans('custom_form_public.easy.always_required') }}）</span>@endif
                    @if($column['warning'])
                    <div class="text-warning small"><i class="fa fa-exclamation-triangle"></i> {{ exmtrans('custom_form_public.easy.warning_' . $column['warning']) }}</div>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- 2. the notice and the text after sending --}}
    <h4 style="margin-top:16px"><span class="badge bg-primary">2</span> {{ exmtrans('custom_form_public.easy.step_notice') }}</h4>
    <div class="form-group" style="max-width:760px">
        <label class="fw-bold">{{ exmtrans('custom_form_public.easy.notify_admin_users') }}</label>
        {{-- the user picker of Exment (search by name, chosen ones as chips) --}}
        <select name="notify_admin_users[]" multiple class="form-control easy-notify" style="width:100%" data-ajax="{{ $users_ajax }}" data-placeholder="{{ exmtrans('custom_form_public.easy.find_user') }}">
            @foreach($default_users as $id => $label)
            <option value="{{ $id }}" selected>{{ $label }}</option>
            @endforeach
        </select>
        <p class="text-muted small">{{ exmtrans('custom_form_public.easy.help.notify_admin_users') }}</p>
        <div class="alert alert-warning easy-no-notice" style="display:none">{{ exmtrans('custom_form_public.easy.warning_no_notice') }}</div>
    </div>
    <div class="form-group" style="max-width:760px">
        <label class="fw-bold">{{ exmtrans('custom_form_public.complete_text') }}</label>
        <textarea name="complete_text" class="form-control" rows="3">{{ $complete_text }}</textarea>
        <p class="text-muted small">{{ exmtrans('custom_form_public.easy.help.complete_text') }}</p>
    </div>

    {{-- more settings, folded --}}
    <details style="max-width:760px;margin:12px 0">
        <summary class="fw-bold" style="cursor:pointer">{{ exmtrans('custom_form_public.easy.more') }}</summary>
        <div style="padding:8px 0 0 8px">
            <div class="form-group">
                <label class="fw-bold">{{ exmtrans('custom_form_public.easy.name') }}</label>
                <input type="text" name="public_form_view_name" class="form-control" maxlength="40" value="{{ $name }}" required>
                <p class="text-muted small">{{ exmtrans('custom_form_public.easy.help.name') }}</p>
            </div>
            <div class="form-group">
                <label class="fw-bold">{{ exmtrans('custom_form_public.easy.notify_user_column') }}</label>
                <select name="notify_user_column" class="form-control">
                    <option value="">{{ exmtrans('custom_form_public.easy.no_receipt') }}</option>
                    @foreach($email_columns as $key => $label)
                    <option value="{{ $key }}" @if($key === $default_email) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="text-muted small">{{ exmtrans('custom_form_public.easy.help.notify_user_column') }}</p>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" name="recaptcha" value="1" @if($robot_available) checked @else disabled @endif> {{ exmtrans('custom_form_public.easy.robot') }}</label>
                <p class="text-muted small">{{ $robot_available ? exmtrans('custom_form_public.easy.help.robot') : exmtrans('custom_form_public.easy.help.robot_off') }}</p>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" name="active" value="1" checked> {{ exmtrans('custom_form_public.easy.active') }}</label>
                <p class="text-muted small">{{ exmtrans('custom_form_public.easy.help.active') }}</p>
            </div>
            <p><a href="{{ $detail_url }}">{{ exmtrans('custom_form_public.easy.detail_create') }}</a></p>
        </div>
    </details>

    {{-- 3. publish --}}
    <h4 style="margin-top:16px"><span class="badge bg-primary">3</span> {{ exmtrans('custom_form_public.easy.step_publish') }}</h4>
    <button type="submit" class="btn btn-primary btn-lg" @if(!$can_add) disabled @endif><i class="fa fa-globe"></i> {{ exmtrans('custom_form_public.easy.publish') }}</button>
</form>
<script>
(function () {
  // jQuery (select2) comes at the end of the page: on a full load, after it; by pjax, now
  var init = function () {
    var form = document.getElementById('public-form-easy');
    if (!form) { return; }
    // "must be filled" ticks "show"; "show" off clears "must be filled"
    form.querySelectorAll('tr[data-column]').forEach(function (row) {
        var show = row.querySelector('.easy-show'), req = row.querySelector('.easy-required');
        req.addEventListener('change', function () { if (req.checked) { show.checked = true; } });
        show.addEventListener('change', function () { if (!show.checked && !req.getAttribute('onclick')) { req.checked = false; } });
    });
    // nobody to tell: said at once
    var notify = form.querySelector('.easy-notify'), warn = form.querySelector('.easy-no-notice');
    var check = function () { warn.style.display = notify.selectedOptions.length ? 'none' : ''; };
    if (window.jQuery && jQuery.fn.select2) {
        jQuery(notify).select2({
            ajax: {
                url: notify.dataset.ajax,
                dataType: 'json',
                delay: 250,
                data: function (params) { return { q: params.term, page: params.page }; },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: jQuery.map(data.data || [], function (d) { return { id: d.id, text: d.text }; }), pagination: { more: !!data.next_page_url } };
                },
                cache: true
            },
            placeholder: notify.dataset.placeholder,
            minimumInputLength: 1,
            width: '100%'
        }).on('change', check);
    } else {
        notify.addEventListener('change', check);
    }
    check();
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
</script>

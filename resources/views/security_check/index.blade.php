{{-- The security check (ISS02833): each item now / the good state / how to fix it; a button where Exment can fix it (SecurityCheckController::fix) --}}
<div class="box box-default">
    <div class="box-body">
        <p>{{ exmtrans('security_check.explain') }}</p>
        @if(!$serverItems)
        <p class="text-muted">{{ exmtrans('security_check.server_items_hidden') }}</p>
        @endif
        <div class="container-fluid">
            <div class="row row-eq-height">
                @foreach($check->items() as $item)
                @php
                    $set = $item->getResultClassSet();
                    $ok = $item->checkResult() == 'ok';
                @endphp
                <div class="col-xs-12 col-sm-6 require_item security_item" data-security="{{ $item->getKey() }}">
                    <h4 class="require_item_header">
                        <i class="fa {{ $set['fontawesome'] }}" aria-hidden="true" style="color: {{ $set['color'] }}"></i>
                        {{ $item->getLabel() }}
                    </h4>
                    <p>{{ $item->getExplain() }}</p>
                    <p>{{ exmtrans('security_check.now') }} : <span class="bold">{{ $item->getResultText() }}</span></p>

                    @if(!$ok)
                        <p class="text-warning">{{ $item->getMessage() }}</p>
                        @if(!is_null($fixText = $item->getFixText()))
                        <pre class="security_fix" style="white-space: pre-wrap;">{{ $fixText }}</pre>
                        @endif
                    @endif

                    <div class="security_buttons">
                        @if(!$ok && $item->canFix())
                        <form method="post" action="{{ $fixUrl }}" style="display:inline" onsubmit="return window.confirm('{{ exmtrans('security_check.fix_confirm') }}');">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <input type="hidden" name="item" value="{{ $item->getKey() }}">
                            <button type="submit" class="btn btn-sm btn-warning">{{ exmtrans('security_check.fix') }}</button>
                        </form>
                        @endif
                        @if(!is_null($url = $item->getSettingUrl()))
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">
                            {{ exmtrans('system_require.check_setting') }}
                        </a>
                        @endif
                    </div>
                </div>
                @endforeach
                {{-- ISS02935: what is in front of the site, tried from this browser --}}
                <div class="col-xs-12 col-sm-6 require_item security_item" data-security="firewall">
                    <h4 class="require_item_header">
                        <i class="fa fa-question-circle" aria-hidden="true" id="exment-fw-icon"></i>
                        {{ exmtrans('security_check.item.firewall.label') }}
                    </h4>
                    <p>{{ exmtrans('security_check.item.firewall.explain') }}</p>
                    <p>{{ exmtrans('security_check.now') }} : <span class="bold" id="exment-fw-result">{{ exmtrans('security_check.item.firewall.not_yet') }}</span></p>
                    <p class="text-warning" id="exment-fw-warning" hidden>{{ exmtrans('security_check.item.firewall.warning') }}
                        <a href="{{ getManualUrl('troubleshooting_firewall') }}" target="_blank" rel="noopener noreferrer">{{ exmtrans('blocked_notice.help') }}</a></p>
                    <pre id="exment-fw-detail" hidden style="white-space: pre-wrap;"></pre>
                    <div class="security_buttons">
                        <button type="button" class="btn btn-sm btn-default" id="exment-fw-run" data-url="{{ $firewallUrl }}">{{ exmtrans('security_check.item.firewall.run') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('exment-fw-run');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var result = document.getElementById('exment-fw-result');
        var warning = document.getElementById('exment-fw-warning');
        var detail = document.getElementById('exment-fw-detail');
        var icon = document.getElementById('exment-fw-icon');
        btn.disabled = true;
        result.textContent = @json(exmtrans('security_check.item.firewall.running'));
        var body = new FormData();
        body.append('_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        // the words a firewall refuses in a body: an URL of this computer, an IP address, a path
        body.append('probe', 'http://localhost/ http://127.0.0.1:8080/ 169.254.169.254 ../../etc/passwd C:\\Windows\\win.ini');
        var done = function (blocked, status, names) {
            btn.disabled = false;
            result.textContent = blocked ? @json(exmtrans('security_check.result_ng')) : @json(exmtrans('security_check.result_ok'));
            icon.className = 'fa ' + (blocked ? 'fa-exclamation-triangle' : 'fa-check-circle');
            icon.style.color = blocked ? '#dd4b39' : '#00a65a';
            warning.hidden = !blocked;
            detail.hidden = !blocked;
            detail.textContent = blocked ? ('HTTP ' + status + '\n' + (names || []).join(', ')) : '';
        };
        fetch(btn.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) {
                var names = [];
                r.headers.forEach(function (v, k) { names.push(k); });
                done(!r.headers.has('X-Exment') || !r.ok, r.status, names);
            }, function () { done(true, 0, []); });
    });
})();
</script>

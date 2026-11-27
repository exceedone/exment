{{-- The install in steps (InstallRunner): InstallingForm@index while it goes on. Plain script, and
     its own styles: the files of the screens (jQuery, Bootstrap) may be published only by the install itself. --}}
@extends('exment::install.layout')
@php
    $json = function ($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); };
    $texts = [
        'phases' => collect(\Exceedone\Exment\Services\Installer\InstallRunner::PHASES)->mapWithKeys(fn ($phase) => [$phase => exmtrans("install.progress.phases.{$phase}")])->all(),
        'migrations' => exmtrans('install.progress.migrations'),
        'waiting' => exmtrans('install.progress.waiting'),
        'connection_lost' => exmtrans('install.progress.connection_lost'),
        'failed' => exmtrans('install.progress.failed'),
        'done' => exmtrans('install.progress.done'),
    ];
@endphp
@section('content')
        <p class="login-box-msg text-center">{{ trans('admin.setting') }}(4/4) : {{ exmtrans('install.progress.header') }}</p>

        <div id="install-progress" aria-live="polite" style="margin: 0 0 1.5em 0;">
            <p class="install-progress-text">{{ exmtrans('install.progress.text') }}</p>
            <div class="progress" style="height: 22px; margin-bottom: 10px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                <div class="progress-bar progress-bar-striped progress-bar-animated install-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" style="width: {{ $progress['percent'] }}%; min-width: 2.5em; height: 100%; background-color: #3c8dbc; color: #fff; text-align: center; line-height: 22px;">{{ $progress['percent'] }}%</div>
            </div>
            <p class="install-progress-phase fw-bold" style="margin-bottom: 4px;">{{ $texts['phases'][$progress['phase']] ?? '' }}</p>
            <p class="install-progress-migrations small text-muted" style="margin-bottom: 4px;">{{ $progress['migrations_total'] > 0 ? exmtrans('install.progress.migrations', ['done' => $progress['migrations_done'], 'total' => $progress['migrations_total']]) : '' }}</p>
            <p class="install-progress-message small text-muted" style="margin-bottom: 0;"></p>

            <div class="install-progress-error text-danger" style="{{ $progress['status'] === 'failed' ? '' : 'display:none;' }} margin-top: 1em;">
                <p style="margin-bottom: 4px;"><i class="fa fa-times-circle-o"></i> {{ exmtrans('install.progress.failed') }}</p>
                <pre class="install-progress-error-text small" style="white-space: pre-wrap; word-break: break-all; color: inherit; margin: 0;">{{ $progress['error'] }}</pre>
            </div>
        </div>

        <noscript><p class="small">{{ exmtrans('install.progress.noscript') }}</p></noscript>

        <div class="row">
            <div class="col-xs-12 col-sm-4">
                <a href="{{ admin_urls('install', 'reset') }}" class="btn btn-default btn-block w-100 install-progress-reset" style="{{ $progress['status'] === 'failed' ? '' : 'display:none;' }}">{{ trans('admin.reset') }}</a>
            </div>
            <div class="col-xs-12 col-sm-8">
                {{-- without script: one step per click; with script: "run again" after a failure --}}
                <form action="{{ $stepUrl }}" method="post" class="install-progress-form" style="margin: 0;">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <input type="hidden" name="retry" value="1">
                    <button type="submit" class="btn btn-primary btn-block w-100 install-progress-retry" style="background-color: #3c8dbc; border-color: #367fa9;">
                        <span class="install-progress-retry-label" data-failed="{{ exmtrans('install.progress.retry') }}">{{ $progress['status'] === 'failed' ? exmtrans('install.progress.retry') : exmtrans('install.progress.continue') }}</span>
                    </button>
                </form>
            </div>
        </div>
@endsection

@section('script')
<script type="text/javascript">
(function () {
    var texts = {!! $json($texts) !!};
    var stepUrl = {!! $json($stepUrl) !!};
    var statusUrl = {!! $json($statusUrl) !!};
    var initializeUrl = {!! $json($initializeUrl) !!};
    var pollMs = {{ intval($pollMs) }};
    var token = {!! $json(csrf_token()) !!};
    var progress = {!! $json($progress) !!};
    var form = document.querySelector('.install-progress-form');
    var failures = 0;
    var stopped = false;

    function el(cls) {
        return document.querySelector('.' + cls);
    }
    function show(node, visible) {
        if (node) {
            node.style.display = visible ? '' : 'none';
        }
    }
    function setText(cls, text) {
        var node = el(cls);
        if (node) {
            node.textContent = text || '';
        }
    }

    function render(p) {
        var percent = Math.max(0, Math.min(100, parseInt(p.percent, 10) || 0));
        var bar = el('install-progress-bar');
        bar.style.width = percent + '%';
        bar.setAttribute('aria-valuenow', percent);
        bar.textContent = percent + '%';
        setText('install-progress-phase', texts.phases[p.phase] || '');
        setText('install-progress-migrations', p.migrations_total > 0
            ? texts.migrations.replace(':done', Number(p.migrations_done).toLocaleString()).replace(':total', Number(p.migrations_total).toLocaleString())
            : '');
        var failed = p.status === 'failed';
        show(el('install-progress-error'), failed);
        setText('install-progress-error-text', p.error);
        show(el('install-progress-reset'), failed);
        show(form, failed);
        // the button can be clicked again (the layout disables the buttons of a form sent)
        var button = el('install-progress-retry');
        if (button) {
            button.disabled = false;
        }
        if (failed) {
            bar.style.backgroundColor = '#dd4b39';
            bar.classList.remove('progress-bar-animated');
        } else {
            bar.style.backgroundColor = '#3c8dbc';
            bar.classList.add('progress-bar-animated');
        }
    }

    function finish(url) {
        stopped = true;
        setText('install-progress-message', texts.done);
        window.location.href = url || initializeUrl;
    }

    // one call of the step; on an answer that is not one (504 of a proxy, the network): again, later
    function step(retry) {
        if (stopped) {
            return;
        }
        var body = new FormData();
        body.append('_token', token);
        if (retry) {
            body.append('retry', '1');
        }
        fetch(stepUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token}
        }).then(read).then(function (p) {
            if (!p) {
                return;
            }
            failures = 0;
            if (!handle(p)) {
                return;
            }
            // busy: another call works, its progress is read until it ends. Else: the next part at once
            if (p.busy) {
                setTimeout(poll, pollMs);
            } else {
                setTimeout(function () { step(false); }, 0);
            }
        }).catch(lost);
    }

    // the progress only (no work), while a call works
    function poll() {
        if (stopped) {
            return;
        }
        fetch(statusUrl, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
        }).then(read).then(function (p) {
            if (!p) {
                return;
            }
            failures = 0;
            if (!handle(p)) {
                return;
            }
            if (p.busy) {
                setTimeout(poll, pollMs);
            } else {
                step(false);
            }
        }).catch(lost);
    }

    function read(res) {
        // the install ended in another call: the site sends to the first setup
        if (res.redirected && !/\/install(\/|$)/.test(new URL(res.url).pathname)) {
            finish(res.url);
            return null;
        }
        // the session is gone (a new token is needed): the page again
        if (res.status === 419) {
            stopped = true;
            window.location.reload();
            return null;
        }
        var type = res.headers.get('Content-Type') || '';
        if (!res.ok || type.indexOf('json') < 0) {
            throw new Error('HTTP ' + res.status);
        }
        return res.json();
    }

    // true: go on
    function handle(p) {
        progress = p;
        render(p);
        setText('install-progress-message', p.busy ? texts.waiting : '');
        if (p.status === 'succeeded' || p.status === 'none') {
            finish(p.redirect);
            return false;
        }
        return p.status !== 'failed';
    }

    // no answer (504 of a proxy, the network): the call may still work on the server; its progress, later
    function lost() {
        failures++;
        setText('install-progress-message', failures > 40 ? texts.connection_lost : texts.waiting);
        setTimeout(poll, Math.min(15000, pollMs * Math.min(failures, 10)));
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        progress.status = 'running';
        progress.error = null;
        render(progress);
        step(true);
    });

    render(progress);
    if (progress.status !== 'failed') {
        step(false);
    }
})();
</script>
@endsection

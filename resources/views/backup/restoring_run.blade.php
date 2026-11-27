{{-- The page of a restore going on (ISS02943): answered by Middleware\BackupRestoreGuard before the session and the database --}}
@php
    $json = function ($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); };
    $texts = [
        'no_token' => exmtrans('backup.run.screen.restoring_no_token'),
        'signin_again' => exmtrans('backup.run.screen.signin_again'),
        'statuses' => collect(\Exceedone\Exment\Model\BackupRun::STATUSES)->mapWithKeys(fn ($status) => [$status => exmtrans("backup.run.statuses.{$status}")])->all(),
        'reasons' => [
            'warning' => exmtrans('backup.run.screen.reasons.warning'),
            'failed' => exmtrans('backup.run.screen.reasons.failed'),
            'stalled' => exmtrans('backup.run.screen.reasons.stalled'),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ exmtrans('backup.run.screen.restoring_title') }}</title>
    <style>
        body { margin: 0; font-family: -apple-system, "Segoe UI", "Hiragino Sans", "Noto Sans JP", Meiryo, sans-serif; background: #f4f6f9; color: #333; }
        main { max-width: 640px; margin: 10vh auto 0; padding: 28px 24px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
        h1 { font-size: 1.25rem; margin: 0 0 16px; }
        .bar { height: 22px; background: #e9edf2; border-radius: 4px; overflow: hidden; }
        .bar span { display: block; height: 100%; min-width: 2.5em; background: #3c8dbc; color: #fff; font-size: 12px; line-height: 22px; text-align: center; transition: width .4s; }
        .bar.done span { background: #00a65a; }
        .bar.bad span { background: #dd4b39; }
        p { line-height: 1.7; margin: 12px 0 0; }
        a { color: #3c8dbc; }
        @media (max-width: 680px) { main { margin: 6vh 16px 0; } }
    </style>
</head>
<body>
    <main role="status" aria-live="polite">
        <h1>{{ exmtrans('backup.run.screen.restoring_title') }}</h1>
        <div class="bar" id="bar"><span id="bar-value" style="width: {{ $progress }}%;">{{ $progress }}%</span></div>
        <p id="stage">{{ $stageLabel }}</p>
        <p id="message">{{ exmtrans('backup.run.screen.restoring_text') }}</p>
        <p id="login" hidden><a href="{{ $loginUrl }}">{{ exmtrans('backup.run.screen.signin') }}</a></p>
    </main>
    <script>
    (function () {
        var texts = {!! $json($texts) !!};
        var uuid = {!! $json($uuid) !!};
        var url = {!! $json($stateUrl) !!};
        var key = 'exment_backup_state_' + uuid;
        var token = null;
        try {
            var match = /[#&]t=([0-9a-f]{64})/.exec(window.location.hash || '');
            if (match) {
                window.sessionStorage.setItem(key, match[1]);
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
            token = window.sessionStorage.getItem(key);
        } catch (e) {
            token = null;
        }
        var bar = document.getElementById('bar');
        var value = document.getElementById('bar-value');
        var stage = document.getElementById('stage');
        var message = document.getElementById('message');
        var login = document.getElementById('login');
        function end(run) {
            try { window.sessionStorage.removeItem(key); } catch (e) {}
            var ok = !run || run.status === 'succeeded' || run.status === 'warning';
            var percent = run ? Math.max(0, Math.min(100, parseInt(run.progress, 10) || 0)) : 100;
            value.style.width = percent + '%';
            value.textContent = percent + '%';
            bar.className = 'bar ' + (ok ? 'done' : 'bad');
            stage.textContent = run && texts.statuses[run.status] ? texts.statuses[run.status] : '';
            message.textContent = (run && texts.reasons[run.status]) || texts.signin_again;
            login.hidden = false;
        }
        if (!token) {
            // another tab or another admin: nothing to read
            message.textContent = texts.no_token;
            login.hidden = false;
            return;
        }
        function poll() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Exment-Run-Token', token);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    var data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (e) {}
                    var run = data && data.run ? data.run : null;
                    if (run && run.active) {
                        var percent = Math.max(0, Math.min(100, parseInt(run.progress, 10) || 0));
                        value.style.width = percent + '%';
                        value.textContent = percent + '%';
                        var text = run.stage_label || '';
                        if (run.stage_total > 0) {
                            text += ' (' + Number(run.stage_done).toLocaleString() + ' / ' + Number(run.stage_total).toLocaleString() + ')';
                        }
                        stage.textContent = text;
                        setTimeout(poll, 2000);
                        return;
                    }
                    end(run);
                    return;
                }
                if (xhr.status === 404) {
                    end(null);
                    return;
                }
                setTimeout(poll, xhr.status === 429 ? 10000 : 4000);
            };
            xhr.onerror = function () { setTimeout(poll, 4000); };
            xhr.send();
        }
        poll();
    })();
    </script>
</body>
</html>

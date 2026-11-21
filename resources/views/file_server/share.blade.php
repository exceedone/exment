{{-- File server, phase 3: the page of a share link or a request link, for people who do not sign in. Data from FileShareController. --}}
<!doctype html>
<html lang="{{ str_starts_with(strval(app()->getLocale()), 'ja') ? 'ja' : 'en' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($item) ? $item->name . ' - ' : '' }}{{ $site_name }}</title>
<style>
:root { --bg: #f4f5f7; --card: #fff; --line: #dde1e6; --text: #222; --muted: #5f6b7a; --accent: #3c8dbc; --error: #c62828; }
@media (prefers-color-scheme: dark) { :root { --bg: #1e1f22; --card: #2b2d31; --line: #3a3d42; --text: #e6e6e6; --muted: #a8b0ba; --accent: #6fb3dc; --error: #ef6b6b; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", "Hiragino Sans", "Yu Gothic UI", sans-serif; line-height: 1.6; }
header { background: var(--card); border-bottom: 1px solid var(--line); padding: 10px 16px; font-weight: 600; }
main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 6px; padding: 20px; margin-bottom: 16px; }
h1 { font-size: 1.25rem; margin: 0 0 8px; overflow-wrap: anywhere; }
.muted { color: var(--muted); font-size: .9rem; }
.btn { display: inline-block; font: inherit; padding: 6px 16px; border-radius: 4px; border: 1px solid var(--accent); background: var(--accent); color: #fff; text-decoration: none; cursor: pointer; }
.btn.sub { background: transparent; color: var(--accent); }
input[type=password] { font: inherit; padding: 6px 10px; border: 1px solid var(--line); border-radius: 4px; background: var(--card); color: var(--text); width: 100%; max-width: 320px; }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 8px 6px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: middle; }
td.r { text-align: right; white-space: nowrap; }
a { color: var(--accent); }
.crumbs { margin-bottom: 10px; overflow-wrap: anywhere; }
.crumbs a + a::before, .crumbs a + span::before { content: '/'; margin: 0 6px; color: var(--muted); }
.preview { background: var(--bg); border-radius: 4px; min-height: 160px; display: flex; align-items: center; justify-content: center; margin: 12px 0; overflow: auto; }
.preview img, .preview video { max-width: 100%; max-height: 70vh; }
.preview iframe { width: 100%; height: 70vh; border: 0; background: #fff; }
.preview pre { width: 100%; max-height: 70vh; margin: 0; padding: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
.drop { border: 2px dashed var(--line); border-radius: 6px; padding: 28px; text-align: center; margin: 12px 0; }
.drop.over { border-color: var(--accent); }
.error { color: var(--error); }
.list li { overflow-wrap: anywhere; }
</style>
</head>
<body>
<header>{{ $site_name }}</header>
<main>
@if ($mode === 'gone')
    <div class="card">
        <h1>{{ exmtrans('file_server.share_gone_title') }}</h1>
        <p class="muted">{{ exmtrans('file_server.share_gone') }}</p>
    </div>

@elseif ($mode === 'password')
    <div class="card">
        <h1>{{ exmtrans('file_server.share_password_title') }}</h1>
        <form method="post" action="{{ $unlock_url }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <p><input type="password" name="password" required autofocus autocomplete="current-password" aria-label="{{ exmtrans('file_server.share_password_title') }}"></p>
            @if (!empty($error))<p class="error">{{ $error }}</p>@endif
            <button type="submit" class="btn">{{ exmtrans('file_server.open') }}</button>
        </form>
    </div>

@elseif ($mode === 'request')
    <div class="card" id="exm-fs-request" data-upload="{{ $upload_url }}" data-max="{{ $max_file_size }}">
        <h1>{{ exmtrans('file_server.share_request_title', ['name' => $folder->name]) }}</h1>
        @if (!empty($link->message))<p style="white-space:pre-wrap">{{ $link->message }}</p>@endif
        <p class="muted">{{ exmtrans('file_server.share_request_note') }}</p>
        @if ($link->expires_at)<p class="muted">{{ exmtrans('file_server.share_expires') }}: {{ $link->expires_at->format('Y/m/d') }}</p>@endif
        <div class="drop" data-drop>{{ exmtrans('file_server.share_drop_here') }}<br><br>
            <label class="btn sub">{{ exmtrans('file_server.upload_files') }}<input type="file" multiple hidden data-input></label>
        </div>
        <ul class="list" data-result></ul>
    </div>
    <script>
    (function () {
        var box = document.getElementById('exm-fs-request');
        var token = document.querySelector('meta[name="csrf-token"]').content;
        var max = Number(box.dataset.max) || 0;
        var list = box.querySelector('[data-result]');
        var tooLarge = @json(exmtrans('file_server.error_too_large'));
        var sent = @json(exmtrans('file_server.share_upload_sent')), failed = @json(exmtrans('file_server.share_upload_failed'));
        function line(text, isError) {
            var li = document.createElement('li');
            li.textContent = text;
            if (isError) { li.className = 'error'; }
            list.appendChild(li);
        }
        function send(files) {
            var p = Promise.resolve();
            Array.prototype.forEach.call(files, function (file) {
                p = p.then(function () {
                    if (max > 0 && file.size > max) { line(file.name + ': ' + tooLarge, true); return; }
                    var fd = new FormData();
                    fd.append('files[]', file, file.name);
                    return fetch(box.dataset.upload, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
                        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j._ok = r.ok; return j; }); })
                        .then(function (j) { line(file.name + ': ' + (j.message || (j._ok ? sent : failed)), !j._ok || j.result === false); })
                        .catch(function (e) { line(file.name + ': ' + e.message, true); });
                });
            });
        }
        box.querySelector('[data-input]').addEventListener('change', function (e) { send(e.target.files); e.target.value = ''; });
        var drop = box.querySelector('[data-drop]');
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
        drop.addEventListener('drop', function (e) { send(e.dataTransfer.files); });
    })();
    </script>

@else
    <div class="card">
        @if (count($breadcrumbs) > 1)
            <div class="crumbs">
                @foreach ($breadcrumbs as $i => $crumb)
                    @if ($i === count($breadcrumbs) - 1)<span>{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif
                @endforeach
            </div>
        @endif
        <h1>{{ $item->name }}</h1>
        <p class="muted">
            @if ($item->isFile())<span data-size="{{ $item->size }}"></span> / @endif
            {{ optional($item->updated_at)->format('Y/m/d H:i') }}
            @if ($link->expires_at) / {{ exmtrans('file_server.share_expires') }}: {{ $link->expires_at->format('Y/m/d') }}@endif
        </p>

        @if ($item->isFolder())
            @if (!empty($zip_url))<p><a class="btn" href="{{ $zip_url }}">{{ exmtrans('file_server.download') }} (ZIP)</a></p>@endif
            <table>
                <tbody>
                    @if (count($children) === 0)
                        <tr><td class="muted">{{ exmtrans('file_server.empty_folder') }}</td></tr>
                    @endif
                    @foreach ($children as $child)
                        <tr>
                            <td>{{ $child['is_folder'] ? '📁' : '📄' }} <a href="{{ $child['url'] }}">{{ $child['name'] }}</a></td>
                            <td class="r muted">@if (!$child['is_folder'])<span data-size="{{ $child['size'] }}"></span>@endif</td>
                            <td class="r">@if ($child['download_url'])<a href="{{ $child['download_url'] }}">{{ exmtrans('file_server.download') }}</a>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            @if ($download_url)<p><a class="btn" href="{{ $download_url }}">{{ exmtrans('file_server.download') }}</a></p>@endif
            <div class="preview">
                @switch($kind)
                    @case('image') <img src="{{ $content_url }}" alt="{{ $item->name }}"> @break
                    @case('video') <video src="{{ $content_url }}" controls preload="metadata" @if (!$download_url) controlslist="nodownload" @endif></video> @break
                    @case('audio') <audio src="{{ $content_url }}" controls preload="metadata"></audio> @break
                    @case('pdf') <iframe src="{{ $content_url }}" title="{{ $item->name }}"></iframe> @break
                    @case('text')
                        @if ($text !== null)<pre>{{ $text }}</pre>@else<p class="muted">{{ exmtrans('file_server.preview_too_large') }}</p>@endif
                        @break
                    @case('office')
                        @if ($pdf_url)<iframe src="{{ $pdf_url }}" title="{{ $item->name }}"></iframe>@else<p class="muted">{{ $download_url ? exmtrans('file_server.preview_none') : exmtrans('file_server.preview_none_no_download') . ' ' . exmtrans('file_server.share_ask_sender') }}</p>@endif
                        @break
                    @default <p class="muted">{{ $download_url ? exmtrans('file_server.preview_none') : exmtrans('file_server.preview_none_no_download') . ' ' . exmtrans('file_server.share_ask_sender') }}</p>
                @endswitch
            </div>
            @if (!$download_url)<p class="muted">{{ exmtrans('file_server.share_preview_only_note') }}</p>@endif
        @endif
    </div>
    <script>
    document.querySelectorAll('[data-size]').forEach(function (el) {
        var b = Number(el.getAttribute('data-size')) || 0, u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
        el.textContent = (i === 0 ? b : b.toFixed(1)) + ' ' + u[i];
    });
    </script>
@endif
</main>
</body>
</html>

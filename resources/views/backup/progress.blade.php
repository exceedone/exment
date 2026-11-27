{{-- The run of the backup or the restore going on (ISS02943): BackupController@index, polled from backup/runs/{uuid}/status --}}
@php
    $json = function ($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); };
    $texts = [
        'started' => exmtrans('backup.run.screen.started'),
        'elapsed' => exmtrans('backup.run.screen.elapsed'),
        'remaining' => exmtrans('backup.run.remaining'),
        'unknown' => exmtrans('backup.run.screen.unknown'),
        'warnings' => exmtrans('backup.run.warning'),
        'errors' => exmtrans('backup.run.error'),
        'restoring' => exmtrans('backup.run.screen.restoring'),
        'finished' => exmtrans('backup.run.screen.finished'),
        'signin_again' => exmtrans('backup.run.screen.signin_again'),
        'signin' => exmtrans('backup.run.screen.signin'),
        'restore' => exmtrans('backup.run.kinds.restore'),
        'statuses' => collect(\Exceedone\Exment\Model\BackupRun::STATUSES)->mapWithKeys(fn ($status) => [$status => exmtrans("backup.run.statuses.{$status}")])->all(),
        'reasons' => [
            'succeeded' => exmtrans('backup.run.screen.signin_again'),
            'warning' => exmtrans('backup.run.screen.reasons.warning'),
            'failed' => exmtrans('backup.run.screen.reasons.failed'),
            'stalled' => exmtrans('backup.run.screen.reasons.stalled'),
        ],
    ];
@endphp
<div class="box box-default backup-progress" id="backup-progress" style="{{ isset($active) ? '' : 'display:none;' }}" aria-live="polite">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-spinner fa-spin backup-progress-spinner" aria-hidden="true"></i> <span class="backup-progress-title">{{ exmtrans('backup.run.screen.progress_header') }}</span></h3>
        <span class="label label-info backup-progress-status float-end"></span>
    </div>
    <div class="box-body">
        <div class="progress backup-progress-bar-wrap" style="height: 22px; margin-bottom: 10px;">
            <div class="progress-bar progress-bar-striped active backup-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width: 0%; min-width: 2.5em;">0%</div>
        </div>
        <p class="backup-progress-stage" style="font-weight: bold; margin-bottom: 6px;"></p>
        <ol class="backup-progress-stages list-unstyled" style="display: flex; flex-wrap: wrap; gap: 6px; padding: 0; margin: 0 0 10px 0;"></ol>
        <dl class="backup-progress-times" style="display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 0;">
            <div><dt style="display:inline; font-weight: normal;" class="text-muted">{{ $texts['started'] }}</dt> <dd style="display:inline; margin:0;" class="backup-progress-started">-</dd></div>
            <div><dt style="display:inline; font-weight: normal;" class="text-muted">{{ $texts['elapsed'] }}</dt> <dd style="display:inline; margin:0;" class="backup-progress-elapsed">-</dd></div>
            <div><dt style="display:inline; font-weight: normal;" class="text-muted">{{ $texts['remaining'] }}</dt> <dd style="display:inline; margin:0;" class="backup-progress-remaining">-</dd></div>
            <div class="backup-progress-problems" style="display:none;"><dd style="display:inline; margin:0;" class="text-danger backup-progress-problems-text"></dd></div>
        </dl>
        <p class="backup-progress-message text-muted" style="margin: 8px 0 0 0; display:none;"></p>
        <p style="margin: 8px 0 0 0;"><a href="#" class="backup-progress-log" style="display:none;">{{ exmtrans('backup.run.screen.view_log') }}</a></p>
    </div>
</div>

<script type="text/javascript">
(function () {
    var texts = {!! $json($texts) !!};
    var active = {!! $json($active) !!};
    var base = {!! $json($statusUrl) !!};
    var pollMs = {{ intval($pollMs) }};
    var loginUrl = {!! $json($loginUrl ?? null) !!};
    var timer = null;
    var current = null;
    var reloadTimer = null;
    var waits = 0;

    // the run ended: the history is shown again (once)
    function reloadSoon() {
        if (reloadTimer !== null || !document.getElementById('backup-progress')) {
            return;
        }
        reloadTimer = setTimeout(function () {
            if (document.getElementById('backup-progress')) {
                $.pjax.reload('#pjax-container');
            }
        }, 1500);
    }

    function duration(seconds) {
        if (seconds === null || seconds === undefined) {
            return texts.unknown;
        }
        seconds = Math.max(0, Math.round(seconds));
        var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60;
        if (h > 0) {
            return h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
        }
        if (m > 0) {
            return m + 'm ' + (s < 10 ? '0' : '') + s + 's';
        }
        return s + 's';
    }

    function render(run) {
        var $box = $('#backup-progress');
        if (!run) {
            return;
        }
        current = run;
        $box.show();
        var percent = Math.max(0, Math.min(100, parseInt(run.progress, 10) || 0));
        var $bar = $box.find('.backup-progress-bar');
        $bar.css('width', percent + '%').attr('aria-valuenow', percent).text(percent + '%');
        $bar.toggleClass('active progress-bar-striped', !!run.active)
            .toggleClass('progress-bar-success bg-success', run.status === 'succeeded')
            .toggleClass('progress-bar-warning bg-warning', run.status === 'warning')
            .toggleClass('progress-bar-danger bg-danger', run.status === 'failed' || run.status === 'stalled');
        $box.find('.backup-progress-spinner').toggle(!!run.active);
        $box.find('.backup-progress-title').text(run.kind_label);
        $box.find('.backup-progress-status').text(run.status_label);
        var stageText = run.active ? (run.stage_label || '') : run.status_label;
        if (run.active && run.stage_total > 0) {
            stageText += ' (' + Number(run.stage_done).toLocaleString() + ' / ' + Number(run.stage_total).toLocaleString() + ')';
        }
        $box.find('.backup-progress-stage').text(stageText);

        var $stages = $box.find('.backup-progress-stages').empty();
        $.each(run.stages || [], function (i, stage) {
            var cls = stage.state === 'done' ? 'label-success' : (stage.state === 'current' ? 'label-primary' : (stage.state === 'stopped' ? 'label-danger' : 'label-default'));
            var icon = stage.state === 'done' ? 'fa-check' : (stage.state === 'current' ? 'fa-circle-o-notch fa-spin' : 'fa-circle-o');
            var $li = $('<li/>').append($('<span class="label"/>').addClass(cls).append($('<i class="fa" aria-hidden="true"/>').addClass(icon)).append(document.createTextNode(' ' + (i + 1) + '. ' + stage.label)));
            if (stage.state === 'current') {
                $li.attr('aria-current', 'step');
            }
            $stages.append($li);
        });

        $box.find('.backup-progress-started').text(run.started_at || '-');
        $box.find('.backup-progress-elapsed').text(duration(run.elapsed_seconds));
        $box.find('.backup-progress-remaining').text(run.active ? duration(run.remaining_seconds) : '-');
        var problems = [];
        if (run.warnings > 0) {
            problems.push(texts.warnings + ' ' + run.warnings);
        }
        if (run.errors > 0) {
            problems.push(texts.errors + ' ' + run.errors);
        }
        $box.find('.backup-progress-problems').toggle(problems.length > 0);
        $box.find('.backup-progress-problems-text').text(problems.join(' / '));
        $box.find('.backup-progress-message').toggle(!!run.message).text(run.message || '');
        $box.find('.backup-progress-log').attr('href', base + '/' + encodeURIComponent(run.uuid)).show();
    }

    function stop() {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    // the token of the state of a restore started by this admin: asked once of the session (then it
    // is gone there), kept in the tab only; the state is read with it while the site is in maintenance
    var tokens = {};
    function tokenKey(uuid) {
        return 'exment_backup_state_' + uuid;
    }
    function stateToken(uuid) {
        if (tokens[uuid]) {
            return tokens[uuid];
        }
        try {
            return window.sessionStorage.getItem(tokenKey(uuid));
        } catch (e) {
            return null;
        }
    }
    function fetchToken(uuid, then) {
        if (stateToken(uuid)) {
            then();
            return;
        }
        $.ajax({ url: base + '/' + encodeURIComponent(uuid) + '/token', type: 'GET', dataType: 'json', cache: false })
            .done(function (data) {
                if (data && typeof data.token === 'string' && /^[0-9a-f]{64}$/.test(data.token)) {
                    tokens[uuid] = data.token;
                    try {
                        window.sessionStorage.setItem(tokenKey(uuid), data.token);
                    } catch (e) {
                        // kept in memory
                    }
                }
            })
            .always(then);
    }
    function forgetToken(uuid) {
        delete tokens[uuid];
        try {
            window.sessionStorage.removeItem(tokenKey(uuid));
        } catch (e) {
            // nothing
        }
    }

    // a restore ended (the state says its status and its percent only): the users were replaced,
    // the session is gone
    function restoreEnded(run) {
        stop();
        var $box = $('#backup-progress');
        var percent = Math.max(0, Math.min(100, parseInt(run.progress, 10) || 0));
        var ok = run.status === null || run.status === 'succeeded' || run.status === 'warning';
        $box.find('.backup-progress-bar').css('width', percent + '%').attr('aria-valuenow', percent).text(percent + '%')
            .removeClass('active progress-bar-striped')
            .toggleClass('progress-bar-success bg-success', run.status === 'succeeded')
            .toggleClass('progress-bar-warning bg-warning', run.status === 'warning')
            .toggleClass('progress-bar-danger bg-danger', !ok);
        $box.find('.backup-progress-spinner').hide();
        var label = run.status ? (texts.statuses[run.status] || '') : texts.finished;
        $box.find('.backup-progress-status').text(label);
        $box.find('.backup-progress-stage').text(label);
        $box.find('.backup-progress-remaining').text('-');
        $box.find('.backup-progress-message').show().text((run.status && texts.reasons[run.status]) || texts.signin_again);
        if (loginUrl) {
            $box.find('.backup-progress-log').attr('href', loginUrl).text(texts.signin).show();
        }
    }

    function poll(uuid) {
        stop();
        // the page was left (pjax): no more polling
        if (!document.getElementById('backup-progress')) {
            return;
        }
        var token = uuid ? stateToken(uuid) : null;
        var url = uuid ? base + '/' + encodeURIComponent(uuid) + (token ? '/state' : '/status') : base + '/current';
        var headers = token ? { 'X-Exment-Run-Token': token } : {};
        $.ajax({ url: url, type: 'GET', dataType: 'json', cache: false, headers: headers })
            .done(function (data) {
                var run = data ? data.run : null;
                if (run && token) {
                    // the state has no id, no kind and no text: they are known here
                    run.uuid = uuid;
                    run.kind = 'restore';
                    run.kind_label = texts.restore;
                    run.status_label = texts.statuses[run.status] || run.status;
                    if (!run.active) {
                        forgetToken(uuid);
                        restoreEnded(run);
                        return;
                    }
                }
                if (run) {
                    render(run);
                }
                if (run && run.active) {
                    timer = setTimeout(function () { poll(run.uuid); }, document.hidden ? pollMs * 3 : pollMs);
                } else if (run && uuid) {
                    // a restore replaced the users and the sessions: sign in again
                    if (run.kind === 'restore') {
                        forgetToken(uuid);
                        restoreEnded({ status: run.status, progress: run.progress });
                        return;
                    }
                    reloadSoon();
                } else if (!run && !uuid && waits < 5) {
                    // the run asked for is not recorded yet (or ended already): a few times more
                    waits++;
                    timer = setTimeout(function () { poll(null); }, pollMs);
                }
            })
            .fail(function (xhr) {
                // a restore puts the site in maintenance (503): it is still going on
                if (xhr && xhr.status === 503) {
                    $('#backup-progress').show().find('.backup-progress-stage').text(texts.restoring);
                }
                // the token is used up or expired: the state is no longer given
                if (token && xhr && xhr.status === 404) {
                    forgetToken(uuid);
                    restoreEnded({ status: null, progress: current ? current.progress : 0 });
                    return;
                }
                timer = setTimeout(function () { poll(uuid); }, pollMs * (xhr && xhr.status === 429 ? 5 : 2));
            });
    }

    window.ExmentBackupProgress = {
        poll: poll,
        stop: stop,
        render: render,
        reloadSoon: reloadSoon,
        current: function () { return current; }
    };

    $(document).off('pjax:start.backupProgress').on('pjax:start.backupProgress', stop);

    if (active) {
        render(active);
        if (active.active) {
            var start = function () {
                timer = setTimeout(function () { poll(active.uuid); }, pollMs);
            };
            // a restore: its token first, while the session still answers
            if (active.kind === 'restore') {
                fetchToken(active.uuid, start);
            } else {
                start();
            }
        }
    }
})();
</script>

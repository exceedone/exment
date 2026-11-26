/*
 * The log of the browser (ISS02904): the errors of the scripts, the requests that failed and,
 * when it is chosen, the pages opened with their time, for the questions of the users. Loaded only
 * for a user who has it (feature management). Kept in memory and sent every few seconds, or when
 * the page is left; never a value typed, a text of the page, a body, the values of a query, a
 * cookie or a token.
 */
(function (window, document) {
    'use strict';
    if (window.ExmentBrowserLog) {
        return;
    }

    var config = null;
    var queue = [];
    var sent = 0;
    var timer = null;
    var last = null;
    var env = null;

    function now() {
        return (window.performance && performance.now) ? performance.now() : Date.now();
    }

    // a mark of the tab (random, kept while the tab is open)
    function tab() {
        var key = 'exment_browser_log_tab';
        try {
            var mark = window.sessionStorage.getItem(key);
            if (mark && /^[0-9a-f]{16}$/.test(mark)) {
                return mark;
            }
            mark = '';
            for (var i = 0; i < 16; i++) {
                mark += Math.floor(Math.random() * 16).toString(16);
            }
            window.sessionStorage.setItem(key, mark);
            return mark;
        } catch (e) {
            return null;
        }
    }

    function browser() {
        var ua = navigator.userAgent || '';
        var rules = [
            ['Edge', /Edg\/(\d+)/], ['Opera', /OPR\/(\d+)/], ['Chrome', /Chrome\/(\d+)/],
            ['Firefox', /Firefox\/(\d+)/], ['Safari', /Version\/(\d+).*Safari/], ['IE', /(?:MSIE |rv:)(\d+)/]
        ];
        for (var i = 0; i < rules.length; i++) {
            var m = ua.match(rules[i][1]);
            if (m) {
                return rules[i][0] + ' ' + m[1];
            }
        }
        return 'other';
    }

    function os() {
        var ua = navigator.userAgent || '';
        var m;
        if ((m = ua.match(/Windows NT (\d+\.\d+)/))) { return 'Windows ' + m[1]; }
        if ((m = ua.match(/Android (\d+)/))) { return 'Android ' + m[1]; }
        if ((m = ua.match(/(?:iPhone|iPad).*OS (\d+)/))) { return 'iOS ' + m[1]; }
        if ((m = ua.match(/Mac OS X (\d+)[._](\d+)/))) { return 'macOS ' + m[1] + '.' + m[2]; }
        if (/CrOS/.test(ua)) { return 'ChromeOS'; }
        if (/Linux/.test(ua)) { return 'Linux'; }
        return 'other';
    }

    function environment() {
        return {
            tab: tab(),
            browser: browser(),
            os: os(),
            screen: (window.screen ? screen.width + 'x' + screen.height : '0x0') + ' ' + window.innerWidth + 'x' + window.innerHeight,
            lang: navigator.language || ''
        };
    }

    // the path of an address: the values of its query hidden (only some keys keep theirs)
    function path(url) {
        try {
            var a = new URL(String(url), window.location.href);
            var keys = [];
            a.searchParams.forEach(function (value, key) {
                var base = key.replace(/\[.*$/, '');
                keys.push(encodeURIComponent(key) + '=' + (config.safeKeys.indexOf(base) >= 0 ? encodeURIComponent(value) : '***'));
            });
            return a.pathname + (keys.length ? '?' + keys.join('&') : '');
        } catch (e) {
            return null;
        }
    }

    function isOwn(url) {
        var p = path(url);
        return p !== null && p.split('?')[0] === path(config.url).split('?')[0];
    }

    function add(event) {
        if (!config || sent + queue.length >= config.max) {
            return;
        }
        // the same error again: counted on the row
        var sign = event.kind + '|' + (event.message || '') + '|' + (event.source || '') + '|' + (event.path || '') + '|' + (event.status || '');
        if (last && last.sign === sign && queue.indexOf(last.event) >= 0) {
            last.event.count = (last.event.count || 1) + 1;
            return;
        }
        event.count = 1;
        queue.push(event);
        last = {sign: sign, event: event};
        if (queue.length >= config.batch) {
            flush(false);
        }
    }

    function flush(leaving) {
        if (!queue.length) {
            return;
        }
        var events = queue.splice(0, 50);
        sent += events.length;
        var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || (window.LA && window.LA.token) || '';
        var data = new FormData();
        data.append('_token', token);
        data.append('events', JSON.stringify(events));
        data.append('env', JSON.stringify(env));
        if (leaving && navigator.sendBeacon) {
            navigator.sendBeacon(config.url, data);
            return;
        }
        try {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.url, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.__exmentBrowserLog = true;
            xhr.send(data);
        } catch (e) {
            // nothing: the log must not break the page
        }
    }

    function source(file, line, column, stack) {
        var lines = [];
        if (file) {
            lines.push(String(file) + ':' + (line || 0) + ':' + (column || 0));
        }
        if (stack) {
            lines = lines.concat(String(stack).split('\n').slice(1, 6));
        }
        return lines.join('\n');
    }

    function onError(e) {
        // an image or a script that did not load: no message
        if (!e || !e.message) {
            return;
        }
        add({kind: 'error', path: path(window.location.href), message: String(e.message), source: source(e.filename, e.lineno, e.colno, e.error && e.error.stack)});
    }

    function onRejection(e) {
        var reason = e && e.reason;
        var message = reason && reason.message ? reason.message : String(reason);
        add({kind: 'error', path: path(window.location.href), message: 'Unhandled rejection: ' + message, source: source(null, 0, 0, reason && reason.stack)});
    }

    function request(method, url, status, started, blocked) {
        if (isOwn(url)) {
            return;
        }
        var event = {kind: 'request', path: path(url), status: status, duration: Math.round(now() - started), method: method};
        // not an answer of Exment (no X-Exment): something in front of the site gave it (ISS02935)
        if (blocked) {
            event.message = 'not answered by Exment (a firewall or a proxy in front?); headers: ' + blocked.join(', ');
        }
        add(event);
    }

    // the names of the headers of an answer that is not Exment's, or null (ISS02935)
    function notExment(status, has, names) {
        if (!status || has) {
            return null;
        }
        return names.slice(0, 20);
    }
    function xhrNames(xhr) {
        try {
            return String(xhr.getAllResponseHeaders() || '').split(/\r?\n/).map(function (line) {
                return line.split(':')[0].trim().toLowerCase();
            }).filter(function (name) { return name !== ''; });
        } catch (e) {
            return [];
        }
    }

    function watchJquery() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        $(document).on('ajaxSend', function (ev, xhr) {
            xhr.__exmentStarted = now();
        });
        $(document).on('ajaxError', function (ev, xhr, settings, thrown) {
            // the user left or stopped it
            if (thrown === 'abort' || (xhr && xhr.statusText === 'abort')) {
                return;
            }
            var mark = false;
            try { mark = !!(xhr && xhr.getResponseHeader('X-Exment')); } catch (e) { mark = false; }
            request(settings.type || 'GET', settings.url, xhr ? xhr.status : 0, xhr && xhr.__exmentStarted ? xhr.__exmentStarted : now(),
                xhr ? notExment(xhr.status, mark, xhrNames(xhr)) : null);
        });
        if (config.pages) {
            var pjaxStarted = null;
            $(document).on('pjax:send', function () {
                pjaxStarted = now();
            });
            $(document).on('pjax:end', function () {
                if (pjaxStarted !== null) {
                    add({kind: 'page', path: path(window.location.href), duration: Math.round(now() - pjaxStarted)});
                    pjaxStarted = null;
                }
            });
        }
    }

    function watchFetch() {
        if (!window.fetch) {
            return;
        }
        var original = window.fetch;
        window.fetch = function (input, init) {
            var started = now();
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var method = (init && init.method) || (input && input.method) || 'GET';
            return original.apply(this, arguments).then(function (response) {
                if (!response.ok) {
                    var names = [];
                    try { response.headers.forEach(function (v, k) { names.push(k); }); } catch (e) { names = []; }
                    request(method, url, response.status, started, notExment(response.status, response.headers.has('X-Exment'), names));
                }
                return response;
            }, function (error) {
                if (!(error && error.name === 'AbortError')) {
                    request(method, url, 0, started);
                }
                throw error;
            });
        };
    }

    function firstPage() {
        if (!config.pages) {
            return;
        }
        var entry = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        var record = function () {
            var duration = entry && entry.loadEventEnd ? entry.loadEventEnd - entry.startTime : now();
            add({kind: 'page', path: path(window.location.href), duration: Math.round(duration)});
        };
        if (document.readyState === 'complete') {
            setTimeout(record, 0);
        } else {
            window.addEventListener('load', function () {
                setTimeout(record, 0);
            });
        }
    }

    window.ExmentBrowserLog = {
        start: function (options) {
            if (config) {
                return;
            }
            config = options || {};
            config.safeKeys = config.safeKeys || [];
            config.batch = config.batch || 20;
            config.max = config.max || 200;
            env = environment();
            window.addEventListener('error', onError);
            window.addEventListener('unhandledrejection', onRejection);
            watchJquery();
            watchFetch();
            firstPage();
            timer = window.setInterval(function () {
                flush(false);
            }, config.interval || 5000);
            window.addEventListener('pagehide', function () {
                flush(true);
            });
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') {
                    flush(true);
                }
            });
        },
        // the tests
        pending: function () {
            return queue.slice();
        },
        flush: function () {
            flush(false);
        }
    };
})(window, document);

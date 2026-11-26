/*
 * When something in front of the site refused what was sent (ISS02935): a firewall (AWS WAF,
 * Cloudflare, a proxy of the company) answers a save, an ajax call, a pjax page or an upload with a
 * 403 / 406 / 413 ... of its own - often because of a URL, an IP address or a path in the text.
 * Every answer of Exment carries the header X-Exment (MarkResponse); one without it is not
 * Exment's, so it is said as what it is, not as "no permission". The form keeps what was typed (an
 * ajax or pjax save does not leave the page).
 *
 * A plain form post that was refused shows the firewall's page instead of this one: when the user
 * comes back to the form with the browser's back button, the notice says the save may not have
 * reached Exment. Only the address and the time of the post are kept for that (sessionStorage),
 * never a value.
 *
 * "Details" shows the status, the names of the headers and the first lines of the answer, as text.
 */
(function (window, document) {
    'use strict';
    if (window.ExmentBlockedNotice) {
        return;
    }
    var STATUSES = [400, 403, 405, 406, 411, 413, 414, 415, 418, 429, 431, 451];
    var PENDING = 'exment_post_pending';
    var config = null;
    var shown = null;

    function headerNames(xhr) {
        try {
            return String(xhr.getAllResponseHeaders() || '').split(/\r?\n/).map(function (line) {
                return line.split(':')[0].trim().toLowerCase();
            }).filter(function (name) { return name !== ''; });
        } catch (e) {
            return [];
        }
    }

    // not an answer of Exment, and one a firewall gives
    function isBlocked(status, hasMark) {
        return !hasMark && STATUSES.indexOf(Number(status)) >= 0;
    }

    // the text of an answer, read without running or loading anything of it (DOMParser)
    function firstLines(text) {
        var plain = '';
        try {
            var doc = new DOMParser().parseFromString(String(text || '').slice(0, 4000), 'text/html');
            Array.prototype.forEach.call(doc.querySelectorAll('script,style'), function (el) { el.remove(); });
            plain = doc.body ? (doc.body.textContent || '') : '';
        } catch (e) {
            plain = '';
        }
        plain = plain.replace(/[ \t]+/g, ' ').split(/\n/).map(function (l) { return l.trim(); })
            .filter(function (l) { return l !== ''; }).slice(0, 5).join('\n');
        return plain.slice(0, 400);
    }

    function show(info) {
        if (!config) {
            return;
        }
        if (shown) {
            shown.remove();
        }
        var box = document.createElement('div');
        box.className = 'exment-blocked-notice';
        box.setAttribute('role', 'alert');
        box.style.cssText = 'position:fixed;z-index:10000;left:50%;top:16px;transform:translateX(-50%);max-width:min(640px,calc(100vw - 32px));'
            + 'background:#fff;border:1px solid #d9534f;border-left:6px solid #d9534f;border-radius:6px;box-shadow:0 6px 24px rgba(0,0,0,.18);'
            + 'padding:12px 14px;font-size:13px;line-height:1.6;color:#333;';
        var title = document.createElement('div');
        title.style.cssText = 'font-weight:700;margin-bottom:4px;';
        title.textContent = info.back ? config.texts.back_title : config.texts.title;
        var body = document.createElement('div');
        body.textContent = config.texts.body;
        var actions = document.createElement('div');
        actions.style.cssText = 'margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;';
        var more = document.createElement('a');
        more.href = 'javascript:void(0);';
        more.textContent = config.texts.details;
        var close = document.createElement('a');
        close.href = 'javascript:void(0);';
        close.textContent = config.texts.close;
        close.style.marginLeft = 'auto';
        actions.appendChild(more);
        if (config.help) {
            var help = document.createElement('a');
            help.href = config.help;
            help.target = '_blank';
            help.rel = 'noopener noreferrer';
            help.textContent = config.texts.help;
            actions.appendChild(help);
        }
        actions.appendChild(close);
        var pre = document.createElement('pre');
        pre.hidden = true;
        pre.style.cssText = 'margin:8px 0 0;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f7f7f7;padding:8px;border-radius:4px;';
        pre.textContent = [
            config.texts.d_status + ': ' + (info.status || '—'),
            config.texts.d_where + ': ' + (info.method || '') + ' ' + (info.path || ''),
            config.texts.d_headers + ': ' + ((info.headers || []).join(', ') || '—'),
            info.text ? config.texts.d_text + ':\n' + info.text : ''
        ].filter(function (l) { return l !== ''; }).join('\n');
        more.addEventListener('click', function () { pre.hidden = !pre.hidden; });
        close.addEventListener('click', function () { box.remove(); shown = null; });
        box.appendChild(title);
        box.appendChild(body);
        box.appendChild(actions);
        box.appendChild(pre);
        document.body.appendChild(box);
        shown = box;
    }

    function pathOf(url) {
        try {
            var a = new URL(String(url || ''), window.location.href);
            return a.pathname;
        } catch (e) {
            return '';
        }
    }

    function fromXhr(xhr, method, url) {
        if (!xhr || !xhr.status) {
            return false;
        }
        var mark = false;
        try { mark = !!xhr.getResponseHeader('X-Exment'); } catch (e) { mark = false; }
        if (!isBlocked(xhr.status, mark)) {
            return false;
        }
        var text = '';
        try { text = typeof xhr.responseText === 'string' ? xhr.responseText : ''; } catch (e) { text = ''; }
        show({status: xhr.status, method: method, path: pathOf(url), headers: headerNames(xhr), text: firstLines(text)});
        return true;
    }

    function watch() {
        var $ = window.jQuery;
        if ($) {
            $(document).on('ajaxError', function (ev, xhr, settings) {
                fromXhr(xhr, settings && settings.type, settings && settings.url);
            });
            $(document).on('pjax:error', function (ev, xhr, textStatus, error, options) {
                // jquery-pjax loads the whole address on an error: the firewall's page would replace
                // this one and the notice with it. Kept here instead.
                if (fromXhr(xhr, options && options.type, options && options.url)) {
                    ev.preventDefault();
                }
            });
        }
        if (window.fetch) {
            var original = window.fetch;
            window.fetch = function (input, init) {
                var url = typeof input === 'string' ? input : (input && input.url) || '';
                var method = (init && init.method) || (input && input.method) || 'GET';
                return original.apply(this, arguments).then(function (response) {
                    if (!response.ok && isBlocked(response.status, response.headers.has('X-Exment'))) {
                        var names = [];
                        response.headers.forEach(function (v, k) { names.push(k); });
                        response.clone().text().then(function (text) {
                            show({status: response.status, method: method, path: pathOf(url), headers: names, text: firstLines(text)});
                        }, function () {
                            show({status: response.status, method: method, path: pathOf(url), headers: names, text: ''});
                        });
                    }
                    return response;
                });
            };
        }
        // a plain form post: its address and time, for coming back to it
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!form || !form.method || String(form.method).toLowerCase() !== 'post' || form.target === '_blank' || ev.defaultPrevented) {
                return;
            }
            try {
                window.sessionStorage.setItem(PENDING, JSON.stringify({path: window.location.pathname, at: Date.now()}));
            } catch (e) { /* nothing */ }
        });
    }

    // a page of Exment loaded: the post before it reached Exment, unless the user came back to
    // the same form with the back button (the firewall's page was in between)
    function checkPending(fromCache) {
        var pending = null;
        try {
            pending = JSON.parse(window.sessionStorage.getItem(PENDING) || 'null');
            window.sessionStorage.removeItem(PENDING);
        } catch (e) {
            return;
        }
        if (!pending || !pending.at || Date.now() - pending.at > 10 * 60 * 1000 || pending.path !== window.location.pathname) {
            return;
        }
        var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        if (fromCache || (nav && nav.type === 'back_forward')) {
            show({back: true, status: '', method: 'POST', path: pending.path, headers: [], text: ''});
        }
    }

    window.ExmentBlockedNotice = {
        start: function (options) {
            if (config) {
                return;
            }
            config = options || {};
            config.texts = config.texts || {};
            watch();
            // the back button shows a page from the cache: pageshow, not load
            window.addEventListener('pageshow', function (ev) {
                if (ev.persisted) {
                    checkPending(true);
                }
            });
            checkPending();
        },
        // for the security check: whether an answer is Exment's
        isBlocked: isBlocked,
        show: show
    };
})(window, document);

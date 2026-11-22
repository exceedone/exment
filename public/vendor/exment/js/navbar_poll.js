var Exment;
(function (Exment) {
    /**
     * One timer for a navbar icon that asks the server on an interval (the notification bell,
     * the workflow tasks) (ISS02824). Every open tab used to ask on its own timer, also when
     * it was in the background. Now:
     * - a hidden tab does not ask; when it is shown again it asks (or reads, see below) at once;
     * - the tabs of one browser share the answer through localStorage: a tab that finds an answer
     *   younger than the interval draws it instead of asking, and the others draw a new answer
     *   as soon as one tab got it (the "storage" event). So one browser asks about once per interval;
     * - the answer carries an ETag, so the browser asks "changed?" and gets a bodiless 304 when not.
     * Without localStorage (private mode, blocked) every tab asks on its own, as before.
     */
    class NavbarPoll {
        /**
         * @param options name: a short name; url: what to ask; intervalMs: how often;
         *                userKey: who is logged in (the answer of one user is never drawn for another);
         *                render: draws an answer
         */
        constructor(options) {
            this.timeoutId = null;
            this.name = options.name;
            this.url = options.url;
            this.intervalMs = options.intervalMs;
            this.render = options.render;
            this.storageKey = 'exment.navbar.' + options.name + '.' + options.userKey;
            const self = this;
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    self.stop();
                }
                else {
                    self.poll(false);
                }
            });
            window.addEventListener('storage', function (event) {
                if (event.key !== self.storageKey || !event.newValue) {
                    return;
                }
                const shared = NavbarPoll.parse(event.newValue);
                if (shared) {
                    self.render(shared.data);
                    self.schedule(self.intervalMs);
                }
            });
        }
        /**
         * Ask now (force: also when another tab has just asked, e.g. after reading an item),
         * or draw what another tab got within the interval.
         */
        poll(force) {
            this.stop();
            if (!force && document.hidden) {
                return;
            }
            if (!force) {
                const shared = this.read();
                if (shared && Date.now() - shared.time < this.intervalMs - 1000) {
                    this.render(shared.data);
                    this.schedule(shared.time + this.intervalMs - Date.now());
                    return;
                }
            }
            const self = this;
            $.ajax({
                url: this.url,
                dataType: "json",
                type: "GET",
                success: function (data) {
                    self.write(data);
                    self.render(data);
                    self.schedule(self.intervalMs);
                },
                error: function () {
                    self.schedule(self.intervalMs);
                },
            });
        }
        stop() {
            if (this.timeoutId !== null) {
                clearTimeout(this.timeoutId);
                this.timeoutId = null;
            }
        }
        schedule(ms) {
            this.stop();
            if (document.hidden) {
                return;
            }
            const self = this;
            this.timeoutId = setTimeout(function () {
                self.poll(false);
            }, Math.max(ms, 1000));
        }
        read() {
            try {
                return NavbarPoll.parse(window.localStorage.getItem(this.storageKey));
            }
            catch (e) {
                return null;
            }
        }
        write(data) {
            try {
                window.localStorage.setItem(this.storageKey, JSON.stringify({ time: Date.now(), data: data }));
            }
            catch (e) {
                // no localStorage: this tab asks on its own
            }
        }
        static parse(value) {
            if (!value) {
                return null;
            }
            try {
                const shared = JSON.parse(value);
                return shared && typeof shared.time === 'number' && shared.data ? shared : null;
            }
            catch (e) {
                return null;
            }
        }
        /**
         * The interval of a hidden input, in milliseconds; at least 30 seconds.
         */
        static intervalMs(selector, defaultSeconds) {
            let seconds = parseInt($(selector).val(), 10);
            if (isNaN(seconds) || seconds < 30) {
                seconds = defaultSeconds;
            }
            return seconds * 1000;
        }
    }
    Exment.NavbarPoll = NavbarPoll;
})(Exment || (Exment = {}));

/**
 * Castle CRM — progressive enhancement.
 *
 * Every create/read/update/delete path in the application works with
 * JavaScript disabled. Nothing here is load-bearing: it makes the UI nicer,
 * and its absence costs convenience rather than function.
 *
 * No framework, no build step, no polyfills — this targets browsers that
 * support ES2020, which is every browser a staff member will have.
 */

(function () {
    'use strict';

    /** @type {string} */
    var basePath = (document.querySelector('meta[name="base-path"]') || {}).content || '/';

    /** @type {string} */
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ---------------------------------------------------------------- Nav

    /**
     * Mobile navigation drawer.
     *
     * Focus moves into the drawer when it opens and returns to the toggle when
     * it closes, so a keyboard or screen-reader user is not left behind.
     */
    function initNav() {
        var toggle = document.querySelector('[data-nav-toggle]');
        var sidebar = document.getElementById('sidebar');

        if (!toggle || !sidebar) {
            return;
        }

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                var firstLink = sidebar.querySelector('a, button');
                if (firstLink) {
                    firstLink.focus();
                }
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!sidebar.classList.contains('is-open'));
        });

        // Click outside to close.
        document.addEventListener('click', function (event) {
            if (!sidebar.classList.contains('is-open')) {
                return;
            }

            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setOpen(false);
                toggle.focus();
            }
        });
    }

    // ------------------------------------------------------------- Alerts

    /** Dismissible flash messages, and auto-dismiss for success only. */
    function initAlerts() {
        document.querySelectorAll('[data-dismiss]').forEach(function (button) {
            button.addEventListener('click', function () {
                var alert = button.closest('.alert');
                if (alert) {
                    alert.remove();
                }
            });
        });

        // Errors and warnings stay until dismissed: a message about something
        // that went wrong should not vanish before it is read.
        document.querySelectorAll('.alert-success').forEach(function (alert) {
            window.setTimeout(function () {
                if (alert.isConnected) {
                    alert.style.transition = 'opacity .3s';
                    alert.style.opacity = '0';
                    window.setTimeout(function () { alert.remove(); }, 300);
                }
            }, 6000);
        });
    }

    // ------------------------------------------------------- Confirmations

    /**
     * Confirm destructive submissions.
     *
     * The server re-checks every one of these, so a user who bypasses the
     * dialog gains nothing. This exists to prevent accidents, not attacks.
     */
    function initConfirms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    // ------------------------------------------------- Double-submit guard

    /**
     * Stop a double-click creating two records.
     *
     * The button is re-enabled after a moment in case the submission was
     * blocked by validation — otherwise a failed submit would leave the form
     * permanently dead.
     */
    function initSubmitGuard() {
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var buttons = form.querySelectorAll('button[type="submit"], button:not([type])');

                buttons.forEach(function (button) {
                    if (button.disabled) {
                        return;
                    }

                    window.setTimeout(function () {
                        button.disabled = true;
                        button.dataset.originalText = button.innerHTML;
                        button.innerHTML = 'Working…';
                    }, 0);

                    window.setTimeout(function () {
                        button.disabled = false;
                        if (button.dataset.originalText) {
                            button.innerHTML = button.dataset.originalText;
                        }
                    }, 8000);
                });
            });
        });
    }

    // ------------------------------------------------------------ Shortcuts

    /**
     * Keyboard shortcuts, ignored while the user is typing.
     */
    function initShortcuts() {
        document.addEventListener('keydown', function (event) {
            var tag = (event.target.tagName || '').toLowerCase();
            var isTyping = tag === 'input' || tag === 'textarea' || tag === 'select'
                || event.target.isContentEditable;

            if (isTyping || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            // "?" opens the shortcut list.
            if (event.key === '?') {
                event.preventDefault();
                showShortcuts();
            }
        });
    }

    function showShortcuts() {
        var existing = document.getElementById('shortcut-help');

        if (existing) {
            existing.remove();
            return;
        }

        var help = document.createElement('div');
        help.id = 'shortcut-help';
        help.setAttribute('role', 'dialog');
        help.setAttribute('aria-label', 'Keyboard shortcuts');
        help.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:100;'
            + 'background:var(--panel);border:1px solid var(--line);border-radius:10px;'
            + 'padding:1rem 1.25rem;box-shadow:var(--shadow-lg);font-size:.85rem;max-width:18rem';

        // textContent throughout: nothing here is interpolated from data, and
        // keeping it that way means this can never become an injection point.
        var title = document.createElement('strong');
        title.textContent = 'Keyboard shortcuts';
        help.appendChild(title);

        var list = document.createElement('dl');
        list.style.cssText = 'margin:.5rem 0 0;display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem';

        [
            ['?', 'This list'],
            ['Esc', 'Close an overlay'],
            ['Tab', 'Move through the page']
        ].forEach(function (pair) {
            var dt = document.createElement('dt');
            dt.textContent = pair[0];
            dt.style.cssText = 'font-family:ui-monospace,monospace;color:var(--brand)';

            var dd = document.createElement('dd');
            dd.textContent = pair[1];
            dd.style.margin = '0';

            list.appendChild(dt);
            list.appendChild(dd);
        });

        help.appendChild(list);
        document.body.appendChild(help);

        window.setTimeout(function () {
            if (help.isConnected) {
                help.remove();
            }
        }, 6000);
    }

    // ------------------------------------------------------ Relative times

    /**
     * Keep "3 minutes ago" honest on a page left open.
     *
     * Only touches elements carrying a machine-readable timestamp, so a static
     * label is never rewritten.
     */
    function initLiveTimes() {
        var nodes = document.querySelectorAll('time[datetime]');

        if (nodes.length === 0) {
            return;
        }

        function refresh() {
            nodes.forEach(function (node) {
                var stamp = Date.parse(node.getAttribute('datetime'));

                if (isNaN(stamp)) {
                    return;
                }

                node.textContent = relative(stamp);
            });
        }

        refresh();
        window.setInterval(refresh, 60000);
    }

    function relative(stamp) {
        var diff = Math.round((Date.now() - stamp) / 1000);
        var future = diff < 0;
        diff = Math.abs(diff);

        var units = [
            [31536000, 'year'],
            [2592000, 'month'],
            [604800, 'week'],
            [86400, 'day'],
            [3600, 'hour'],
            [60, 'minute']
        ];

        for (var i = 0; i < units.length; i++) {
            if (diff >= units[i][0]) {
                var count = Math.floor(diff / units[i][0]);
                var label = count + ' ' + units[i][1] + (count === 1 ? '' : 's');
                return future ? 'in ' + label : label + ' ago';
            }
        }

        return future ? 'in a moment' : 'just now';
    }

    // ------------------------------------------------------------- Exports

    // A tiny shared surface for later modules (Kanban, inbox, charts) rather
    // than each one re-deriving the base path and token.
    window.CastleCRM = {
        basePath: basePath,
        csrfToken: csrfToken,

        /** Build an application URL, honouring a subdirectory install. */
        url: function (path) {
            return basePath.replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
        },

        /** fetch() with the CSRF token attached. */
        request: function (path, options) {
            options = options || {};
            options.headers = Object.assign({
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }, options.headers || {});
            options.credentials = 'same-origin';

            return fetch(this.url(path), options).then(function (response) {
                if (!response.ok) {
                    return response.json()
                        .catch(function () { return { error: 'Request failed.' }; })
                        .then(function (body) {
                            throw new Error(body.error || 'Request failed.');
                        });
                }

                return response.json();
            });
        },

        relative: relative
    };

    // ---------------------------------------------------------------- Boot

    function boot() {
        initNav();
        initAlerts();
        initConfirms();
        initSubmitGuard();
        initShortcuts();
        initLiveTimes();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());

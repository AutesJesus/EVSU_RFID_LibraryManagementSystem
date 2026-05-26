/**
 * Admin/faculty AJAX helpers (same-origin session cookies).
 */
(function () {
    function safeText(s) {
        return String(s == null ? '' : s);
    }

    function ensureToastHost() {
        var host = document.getElementById('uiToastHost');
        if (host) return host;
        host = document.createElement('div');
        host.id = 'uiToastHost';
        host.className = 'ui-toast-host';
        document.body.appendChild(host);
        return host;
    }

    function iconSvg(kind) {
        if (kind === 'error') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>';
        }
        if (kind === 'warn') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 4.2h3.4l8.6 15H1.7z"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    }

    function showToast(kind, text, opts) {
        try {
            var msg = safeText(text).trim();
            if (!msg) return;
            var o = opts || {};
            var timeoutMs = typeof o.timeoutMs === 'number' ? o.timeoutMs : 3600;

            var host = ensureToastHost();
            var toast = document.createElement('div');
            toast.className = 'ui-toast ui-toast--' + (kind || 'success');
            toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');

            toast.innerHTML =
                '<div class="ui-toast__icon">' + iconSvg(kind) + '</div>' +
                '<div class="ui-toast__body">' +
                    '<div class="ui-toast__title">' + (kind === 'error' ? 'Error' : (kind === 'warn' ? 'Notice' : 'Success')) + '</div>' +
                    '<div class="ui-toast__text"></div>' +
                '</div>' +
                '<button class="ui-toast__close" type="button" aria-label="Dismiss">' +
                    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>' +
                '</button>';

            var textEl = toast.querySelector('.ui-toast__text');
            if (textEl) textEl.textContent = msg;

            var closeBtn = toast.querySelector('.ui-toast__close');
            function dismiss() {
                toast.classList.add('is-leaving');
                window.setTimeout(function () {
                    if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
                }, 220);
            }
            if (closeBtn) closeBtn.addEventListener('click', dismiss);

            host.appendChild(toast);
            window.setTimeout(function () { toast.classList.add('is-in'); }, 10);

            if (timeoutMs > 0) {
                window.setTimeout(dismiss, timeoutMs);
            }
        } catch (e) {}
    }

    window.uiToast = function (kind, text, opts) {
        showToast(kind, text, opts);
    };

    function messageFromAjaxData(data) {
        if (!data || typeof data !== 'object') return '';
        return safeText(data.message || data.error || data.flash || '').trim();
    }

    function notifyFromAjaxData(data) {
        var msg = messageFromAjaxData(data);
        if (!msg) return;
        showToast(data && data.ok ? 'success' : 'error', msg);
    }

    window.ajaxNotify = notifyFromAjaxData;

    window.showActionMessage = function (text, isErr) {
        var msg = safeText(text).trim();
        if (!msg) return;
        showToast(isErr ? 'error' : 'success', msg);
        var el = document.getElementById('ajaxFlash');
        if (el) {
            el.textContent = msg;
            el.className = 'msg ' + (isErr ? 'err' : 'ok');
            el.style.display = '';
            el.setAttribute('role', isErr ? 'alert' : 'status');
        }
    };

    function setNextToast(kind, text) {
        try {
            var msg = safeText(text).trim();
            if (!msg) return;
            sessionStorage.setItem('__ui_toast', JSON.stringify({ kind: kind || 'success', text: msg, ts: Date.now() }));
        } catch (e) {}
    }

    function consumeNextToast() {
        try {
            var raw = sessionStorage.getItem('__ui_toast');
            if (!raw) return null;
            sessionStorage.removeItem('__ui_toast');
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function appendAjax(fd) {
        if (!(fd instanceof FormData)) {
            return fd;
        }
        fd.set('__ajax', '1');
        return fd;
    }

    window.ajaxPostFd = function (url, fd) {
        var body = fd instanceof FormData ? fd : new FormData();
        appendAjax(body);
        return fetch(url || window.location.pathname, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' },
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    data = data || {};
                    data.ok = false;
                    if (!data.message) {
                        data.message = 'Request failed (' + res.status + ').';
                    }
                }
                return data;
            });
        });
    };

    window.ajaxReloadOnSuccess = function (data) {
        if (data && data.ok) {
            var msg = messageFromAjaxData(data);
            if (msg) {
                setNextToast('success', msg);
            }
            window.location.reload();
            return true;
        }
        notifyFromAjaxData(data);
        return false;
    };

    window.ajaxPostForm = function (form) {
        var action = form.getAttribute('action') || window.location.pathname;
        var fd = appendAjax(new FormData(form));
        return fetch(action, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' },
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    data = data || {};
                    data.ok = false;
                    if (!data.message) {
                        data.message = 'Request failed (' + res.status + ').';
                    }
                }
                if (data && data.ok && data.message) {
                    // Persist across reloads (many admin forms reload on success).
                    setNextToast('success', data.message);
                    // Also show immediately if the page doesn't reload.
                    showToast('success', data.message);
                }
                if (data && !data.ok) {
                    notifyFromAjaxData(data);
                    if (window.adminShakeModal) {
                        var modal = form.closest('.modal');
                        if (modal && modal.classList.contains('is-open')) {
                            window.adminShakeModal(modal);
                        }
                    }
                }
                return data;
            });
        });
    };

    window.ajaxPostJson = function (url, obj) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'fetch',
            },
            body: JSON.stringify(obj || {}),
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    data = data || {};
                    data.ok = false;
                    if (!data.message) {
                        data.message = 'Request failed (' + res.status + ').';
                    }
                }
                if (data && data.ok && data.message) {
                    setNextToast('success', data.message);
                    showToast('success', data.message);
                } else if (data && !data.ok) {
                    notifyFromAjaxData(data);
                }
                return data;
            });
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        // 1) Toast queued from a previous AJAX success + reload.
        var next = consumeNextToast();
        if (next && next.text) {
            showToast(next.kind || 'success', next.text);
        }

        // 2) Toast from classic POST (server-rendered flash).
        var okMsg = document.querySelector('.msg.ok');
        if (okMsg && okMsg.textContent) {
            var t = safeText(okMsg.textContent).trim();
            if (t) {
                showToast('success', t);
                okMsg.style.display = 'none';
            }
        }

        var errMsg = document.querySelector('.msg.err');
        if (errMsg && errMsg.textContent) {
            var et = safeText(errMsg.textContent).trim();
            if (et) {
                showToast('error', et);
                errMsg.style.display = 'none';
            }
        }
    });
})();

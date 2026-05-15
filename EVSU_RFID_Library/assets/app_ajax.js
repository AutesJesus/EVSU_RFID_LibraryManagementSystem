/**
 * Admin/faculty AJAX helpers (same-origin session cookies).
 */
(function () {
    function appendAjax(fd) {
        if (!(fd instanceof FormData)) {
            return fd;
        }
        fd.set('__ajax', '1');
        return fd;
    }

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
                return data;
            });
        });
    };
})();

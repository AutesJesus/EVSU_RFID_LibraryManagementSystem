/**
 * Compact UI on narrow viewports — icon-only toolbars with hover/focus tooltips.
 */
(function () {
    'use strict';

    var MQ = '(max-width: 900px)';

    var ICONS = {
        apply: '<path d="M20 6 9 17l-5-5"/>',
        clear: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        print: '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
        export: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        add: '<path d="M12 5v14"/><path d="M5 12h14"/>',
        delete: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        cancel: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        search: '<path d="M21 21l-4.3-4.3"/><circle cx="11" cy="11" r="7"/>',
        issue: '<path d="M7 4h10v16H7z"/><path d="M17 8h2a2 2 0 0 1 2 2v10H7"/>',
        toggle: '<path d="M21 12a9 9 0 1 1-9-9"/><path d="M21 3v6h-6"/>',
        rfid: '<path d="M4 12h2"/><path d="M18 12h2"/><path d="M12 4v2"/><path d="M12 18v2"/><circle cx="12" cy="12" r="4"/>',
        back: '<path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/>',
        default: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>'
    };

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function normalizeLabel(text) {
        return String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function pickIconKey(label) {
        if (label.indexOf('apply') !== -1 || label.indexOf('filter') !== -1) return 'apply';
        if (label.indexOf('clear') !== -1) return 'clear';
        if (label.indexOf('print') !== -1) return 'print';
        if (label.indexOf('export') !== -1 || label.indexOf('csv') !== -1) return 'export';
        if (label.indexOf('add') !== -1 || label.indexOf('issue book') !== -1) return 'add';
        if (label.indexOf('issue') !== -1) return 'issue';
        if (label.indexOf('delete') !== -1 || label.indexOf('remove') !== -1) return 'delete';
        if (label.indexOf('save') !== -1 || label.indexOf('update') !== -1) return 'save';
        if (label.indexOf('cancel') !== -1) return 'cancel';
        if (label.indexOf('close') !== -1) return 'close';
        if (label.indexOf('edit') !== -1) return 'edit';
        if (label.indexOf('scan') !== -1 || label.indexOf('rfid') !== -1) return 'rfid';
        if (label.indexOf('toggle') !== -1) return 'toggle';
        if (label.indexOf('back') !== -1) return 'back';
        if (label.indexOf('search') !== -1) return 'search';
        return 'default';
    }

    function iconSvg(key) {
        var paths = ICONS[key] || ICONS.default;
        return '<span class="btn-ico" aria-hidden="true"><svg viewBox="0 0 24 24">' + paths + '</svg></span>';
    }

    function shouldSkip(el) {
        if (!el || el.dataset.compactSkip === '1') return true;
        if (el.classList.contains('btn-icon-tip')) return true;
        if (el.classList.contains('student-edit-profile-btn')) return true;
        if (el.classList.contains('portal-menu-btn')) return true;
        if (el.classList.contains('profile-modal-tab')) return true;
        if (el.closest('.inventory-tabs')) return true;
        if (el.closest('.modal-footer') || el.closest('.user-modal-footer')) return true;
        if (el.closest('.issue-summary')) return true;
        var text = normalizeLabel(el.textContent);
        if (text === '' || text.length > 28) return true;
        return false;
    }

    function compactButton(el) {
        if (shouldSkip(el)) return;

        var label = el.textContent.replace(/\s+/g, ' ').trim();
        if (!label) return;

        var stored = el.dataset.compactLabel || label;
        el.dataset.compactLabel = stored;
        el.dataset.tip = stored;
        el.setAttribute('title', stored);
        if (!el.getAttribute('aria-label')) {
            el.setAttribute('aria-label', stored);
        }

        var key = pickIconKey(normalizeLabel(stored));
        el.classList.add('btn-icon-tip');
        el.innerHTML = iconSvg(key) + '<span class="btn-text">' + stored + '</span>';
    }

    function restoreButton(el) {
        if (!el || !el.dataset.compactLabel) return;
        el.classList.remove('btn-icon-tip');
        el.textContent = el.dataset.compactLabel;
        delete el.dataset.tip;
        delete el.dataset.compactLabel;
        el.removeAttribute('title');
    }

    function collectTargets() {
        var sel = [
            '.control-right .btn',
            '.control-right a.btn',
            '.inventory-actionbar .btn',
            '.inventory-actionbar a.btn',
            '.card-header-bar .btn',
            '.card-header-bar a.btn',
            '.logs-toolbar-end .btn',
            '.logs-toolbar-end a.btn',
            '.borrow-toolbar-end .btn',
            '.directory-data-table .btn-sm',
            '.users-directory-table .btn-sm',
            'table .btn-sm'
        ].join(',');
        return Array.prototype.slice.call(document.querySelectorAll(sel));
    }

    function applyCompact(mode) {
        var nodes = collectTargets();
        nodes.forEach(function (el) {
            if (mode === 'on') {
                compactButton(el);
            } else {
                restoreButton(el);
            }
        });
        document.body.classList.toggle('ui-compact', mode === 'on');
    }

    function init() {
        if (!window.matchMedia) return;
        var mq = window.matchMedia(MQ);

        function sync() {
            applyCompact(mq.matches ? 'on' : 'off');
        }

        sync();
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', sync);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(sync);
        }
    }

    ready(init);
})();

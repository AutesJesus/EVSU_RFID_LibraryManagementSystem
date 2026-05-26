/**
 * Admin UI motion — ripples, row stagger, modal polish, filter press feedback.
 */
(function () {
    'use strict';

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function isEmptyRow(row) {
        var cell = row.querySelector('td');
        if (!cell) return true;
        if (row.cells && row.cells.length === 1 && cell.classList.contains('muted')) return true;
        return false;
    }

    function initPageEnter() {
        document.body.classList.add('admin-motion-ready');
    }

    function staggerTableRows() {
        if (reduced) return;
        var tables = document.querySelectorAll(
            '.directory-data-table tbody, .users-directory-table tbody, .inventory-card table tbody, .card table tbody'
        );
        tables.forEach(function (tbody) {
            var delay = 0;
            var count = 0;
            tbody.querySelectorAll('tr').forEach(function (row) {
                if (isEmptyRow(row)) return;
                if (count >= 60) return;
                row.classList.add('motion-row-in');
                row.style.animationDelay = Math.min(delay, 0.48) + 's';
                delay += 0.03;
                count += 1;
            });
        });
    }

    function addRipple(el, clientX, clientY) {
        if (reduced) return;
        var rect = el.getBoundingClientRect();
        var size = Math.max(rect.width, rect.height) * 1.2;
        var ripple = document.createElement('span');
        ripple.className = 'btn-ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (clientY - rect.top - size / 2) + 'px';
        el.appendChild(ripple);
        window.setTimeout(function () {
            if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
        }, 560);
    }

    function initRipples() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn, .icon-btn');
            if (!btn || btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
            addRipple(btn, e.clientX, e.clientY);
        }, { passive: true });
    }

    function initFilterTabPress() {
        document.addEventListener('click', function (e) {
            var tab = e.target.closest('.inventory-tabs .btn, .inventory-tabs a.btn');
            if (!tab || reduced) return;
            tab.classList.remove('filter-tab-press');
            void tab.offsetWidth;
            tab.classList.add('filter-tab-press');
            window.setTimeout(function () {
                tab.classList.remove('filter-tab-press');
            }, 240);
        }, { passive: true });
    }

    function shakeModal(modal) {
        if (!modal || reduced) return;
        modal.classList.remove('is-shaking');
        void modal.offsetWidth;
        modal.classList.add('is-shaking');
        window.setTimeout(function () {
            modal.classList.remove('is-shaking');
        }, 450);
    }

    window.adminShakeModal = shakeModal;

    function hookAjaxFlash() {
        var flash = document.getElementById('ajaxFlash');
        if (!flash) return;
        var obs = new MutationObserver(function () {
            if (flash.style.display === 'none' || !flash.textContent.trim()) return;
            flash.classList.remove('motion-flash-repeat');
            void flash.offsetWidth;
            flash.classList.add('motion-flash-repeat');
        });
        obs.observe(flash, { attributes: true, attributeFilter: ['style', 'class'], childList: true, characterData: true, subtree: true });
    }

    function initPortalMobileNav() {
        var menuBtn = document.getElementById('portalMenuBtn');
        var sidebar = document.getElementById('portalSidebar');
        var backdrop = document.getElementById('portalSidebarBackdrop');
        if (!menuBtn || !sidebar) return;

        function setOpen(open) {
            document.body.classList.toggle('portal-nav-open', open);
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            if (backdrop) {
                backdrop.hidden = !open;
            }
        }

        function closeNav() {
            setOpen(false);
        }

        menuBtn.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('portal-nav-open'));
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeNav);
        }

        sidebar.querySelectorAll('.admin-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 979px)').matches) {
                    closeNav();
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('portal-nav-open')) {
                closeNav();
            }
        });

        window.addEventListener('resize', function () {
            if (window.matchMedia('(min-width: 980px)').matches) {
                closeNav();
            }
        });
    }

    ready(function () {
        initPageEnter();
        initPortalMobileNav();
        staggerTableRows();
        initRipples();
        initFilterTabPress();
        hookAjaxFlash();
    });
})();

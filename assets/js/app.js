/* app.js — Global JavaScript loaded on every admin page.
   Handles: alert auto-dismiss, confirm dialogs, mobile sidebar overlay,
   table row counts, auto-submit filters, back-to-top, keyboard shortcuts,
   empty state upgrades, and active breadcrumb. */

document.addEventListener('DOMContentLoaded', function () {

    // ─── 1. AUTO-DISMISS ALERTS ─────────────────────────────────────────────
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 5000);
    });

    // ─── 2. CONFIRM DIALOGS ─────────────────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    // ─── 3. MOBILE SIDEBAR BACKDROP ─────────────────────────────────────────
    // The toggle click-listener lives in header.php (runs before DOMContentLoaded
    // to prevent layout flash). It already handles .collapsed on desktop and
    // .mobile-open on mobile. Here we only add the backdrop-click-to-close
    // behaviour so the user can dismiss the sidebar by tapping outside it.
    var sidebar = document.getElementById('sidebar');

    if (sidebar){
        var backdrop = document.createElement('div');
        backdrop.id = 'sidebar-backdrop';
        backdrop.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;cursor:pointer;';
        document.body.appendChild(backdrop);

        var sidebarObserver = new MutationObserver(function () {
            backdrop.style.display = (window.innerWidth <= 768 && sidebar.classList.contains('mobile-open'))
                ? 'block' : 'none';
        });
        sidebarObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

        backdrop.addEventListener('click', function () {
            sidebar.classList.remove('mobile-open');
            backdrop.style.display = 'none';
        });
    }

    document.querySelectorAll('.card').forEach(function (card) {
        var tbody = card.querySelector('tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr')).filter(function (tr) {
            return !tr.querySelector('td[colspan]');
        });
        var count = rows.length;

        // Find a sensible place to insert: look for a card-header first,
        // then fall back to inserting before the card itself.
        var cardHeader = card.querySelector('.card-header');
        if (cardHeader) {
            var badge = document.createElement('span');
            badge.className = 'row-count-badge';
            badge.textContent = count + ' result' + (count !== 1 ? 's' : '');
            cardHeader.appendChild(badge);
        }
    });

    // ─── 5. AUTO-SUBMIT FILTER DROPDOWNS ────────────────────────────────────
    // Any <select> inside a GET filter form auto-submits on change.
    document.querySelectorAll('form[method="GET"] select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            this.closest('form').submit();
        });
    });

    // ─── 6. BACK-TO-TOP BUTTON ──────────────────────────────────────────────
    var btt = document.createElement('button');
    btt.id = 'back-to-top';
    btt.innerHTML = '<i class="bi bi-arrow-up"></i>';
    btt.title = 'Back to top';
    btt.setAttribute('aria-label', 'Back to top');
    document.body.appendChild(btt);

    // .main-content is the scroll container (overflow-y: auto).
    // Fall back to window for pages that don't have it (e.g. login).
    var scrollEl = document.querySelector('.main-content') || window;

    function onScroll() {
        var top = (scrollEl === window) ? window.scrollY : scrollEl.scrollTop;
        btt.classList.toggle('btt-visible', top > 280);
    }

    scrollEl.addEventListener('scroll', onScroll, { passive: true });

    btt.addEventListener('click', function () {
        scrollEl.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // ─── 7. KEYBOARD SHORTCUTS ──────────────────────────────────────────────
    // "/" → focus the first visible search input on the page
    // "n" / "N" → click the first primary "Add / Book / Create" button
    // Both are suppressed when focus is already inside an input/textarea/select

    // Inject a small "/" hint after the first search input group
    var firstSearch = document.querySelector('input[name="search"], input[type="search"]');
    if (firstSearch) {
        var hint = document.createElement('span');
        hint.className = 'kbd-hint';
        hint.innerHTML = 'Press <kbd>/</kbd> to search';
        var parent = firstSearch.closest('.input-group') || firstSearch.parentNode;
        if (parent && parent.parentNode) {
            parent.parentNode.insertBefore(hint, parent.nextSibling);
        }
    }

    document.addEventListener('keydown', function (e) {
        var tag = document.activeElement ? document.activeElement.tagName : '';
        var inInput = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                       || document.activeElement.isContentEditable);

        if (e.key === '/' && !inInput && !e.ctrlKey && !e.metaKey) {
            var searchInput = document.querySelector(
                'input[name="search"], input[type="search"], input[placeholder*="earch"]'
            );
            if (searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }

        if ((e.key === 'n' || e.key === 'N') && !inInput && !e.ctrlKey && !e.metaKey) {
            var addBtn = document.querySelector(
                'a.btn-primary[href*="add"], a.btn-primary[href*="create"], a.btn-primary[href*="book"]'
            );
            if (addBtn) {
                e.preventDefault();
                addBtn.click();
            }
        }
    });

    // ─── 8. IMPROVED EMPTY STATES ───────────────────────────────────────────
    // Replace plain "No X found." text rows with a nicer icon + message block.
    var emptyConfig = {
        'No patients yet.'         : { icon: 'bi-people',          msg: 'No patients yet',          hint: 'Add your first patient to get started.', action: null },
        'No appointments found.'   : { icon: 'bi-calendar-x',      msg: 'No appointments found',    hint: 'Try adjusting your filters.', action: null },
        'No bills found.'          : { icon: 'bi-receipt',         msg: 'No bills found',           hint: 'Bills will appear here once created.', action: null },
        'No dental records found.' : { icon: 'bi-journal-medical', msg: 'No dental records',        hint: 'No treatment records have been added yet.', action: null },
        'No logs found.'           : { icon: 'bi-shield-check',    msg: 'No activity yet',          hint: 'Audit logs will appear here as actions are taken.', action: null },
        'No users found.'          : { icon: 'bi-person-x',        msg: 'No users found',           hint: 'No users match your search.', action: null }
    };

    document.querySelectorAll('td[colspan]').forEach(function (td) {
        var text = td.textContent.trim();

        // Handle "No results for X" search variant
        var isSearch = text.indexOf('No results for') === 0;
        var cfg = null;

        if (!isSearch) {
            Object.keys(emptyConfig).forEach(function (key) {
                if (text.indexOf(key.replace('.','')) !== -1 || text === key || text.replace('.','') === key.replace('.','')) {
                    cfg = emptyConfig[key];
                }
            });
        }

        // Build improved empty state
        if (cfg || isSearch) {
            var icon    = cfg ? cfg.icon : 'bi-search';
            var msg     = isSearch ? 'No results found' : (cfg ? cfg.msg : text);
            var hint    = isSearch ? 'Try a different search term or clear the filter.' : (cfg ? cfg.hint : '');

            td.style.cssText = 'text-align:center;padding:48px 24px;';
            td.innerHTML =
                '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:10px;max-width:320px;">' +
                  '<div style="width:52px;height:52px;border-radius:14px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;">' +
                    '<i class="bi ' + icon + '" style="font-size:1.5rem;color:var(--gray-400);"></i>' +
                  '</div>' +
                  '<div>' +
                    '<div style="font-family:\'Sora\',sans-serif;font-weight:600;font-size:0.9rem;color:var(--gray-700);margin-bottom:4px;">' + msg + '</div>' +
                    '<div style="font-size:0.8rem;color:var(--gray-400);line-height:1.5;">' + hint + '</div>' +
                  '</div>' +
                '</div>';
        }
    });

    // ─── 9. ACTIVE BREADCRUMB ────────────────────────────────────────────────
    // Read the page title from the topbar and inject a slim breadcrumb line
    // below it so users always know where they are.
    var titleBlock = document.querySelector('.topbar-title-block');
    if (titleBlock) {
        var path = window.location.pathname;
        var crumbs = ['Home'];

        if (path.indexOf('/modules/') !== -1) {
            var parts = path.split('/');
            var modIdx = parts.indexOf('modules');
            if (modIdx !== -1 && parts[modIdx + 1]) {
                var section = parts[modIdx + 1];
                section = section.charAt(0).toUpperCase() + section.slice(1);
                crumbs.push(section);
            }
        }

        var pageTitle = document.querySelector('.page-title');
        var titleText = pageTitle ? pageTitle.textContent.trim() : '';
        if (crumbs.length === 1 || crumbs[crumbs.length - 1].toLowerCase() !== titleText.toLowerCase()) {
            crumbs.push(titleText);
        }

        if (crumbs.length > 1) {
            var bc = document.createElement('div');
            bc.id = 'breadcrumb';
            bc.innerHTML = crumbs.map(function (c, i) {
                if (i === crumbs.length - 1) return '<span class="bc-current">' + c + '</span>';
                return '<span class="bc-item">' + c + '</span>';
            }).join('<span class="bc-sep"><i class="bi bi-chevron-right"></i></span>');
            titleBlock.appendChild(bc);
        }
    }

});

/* app.js — Global JavaScript loaded on every admin page. */

function initPage() {

    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 5000);
    });

    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    document.querySelectorAll('.card').forEach(function (card) {
        var tbody = card.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr')).filter(function (tr) {
            return !tr.querySelector('td[colspan]');
        });
        var count = rows.length;
        var cardHeader = card.querySelector('.card-header');
        if (cardHeader && !cardHeader.querySelector('.row-count-badge')) {
            var badge = document.createElement('span');
            badge.className = 'row-count-badge';
            badge.textContent = count + ' result' + (count !== 1 ? 's' : '');
            cardHeader.appendChild(badge);
        }
    });

    document.querySelectorAll('form[method="GET"] select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            this.closest('form').submit();
        });
    });

    var btt = document.getElementById('back-to-top');
    if (!btt) {
        btt = document.createElement('button');
        btt.id = 'back-to-top';
        btt.innerHTML = '<i class="bi bi-arrow-up"></i>';
        btt.title = 'Back to top';
        btt.setAttribute('aria-label', 'Back to top');
        document.body.appendChild(btt);
    }
    var scrollEl = document.querySelector('.main-content') || window;
    function onScroll() {
        var top = (scrollEl === window) ? window.scrollY : scrollEl.scrollTop;
        btt.classList.toggle('btt-visible', top > 280);
    }
    scrollEl.removeEventListener('scroll', onScroll);
    scrollEl.addEventListener('scroll', onScroll, { passive: true });
    btt.onclick = function () { scrollEl.scrollTo({ top: 0, behavior: 'smooth' }); };

    var firstSearch = document.querySelector('input[name="search"], input[type="search"]');
    if (firstSearch && !firstSearch.dataset.hintAdded) {
        firstSearch.dataset.hintAdded = '1';
        var hint = document.createElement('span');
        hint.className = 'kbd-hint';
        hint.innerHTML = 'Press <kbd>/</kbd> to search';
        var parent = firstSearch.closest('.input-group') || firstSearch.parentNode;
        if (parent && parent.parentNode) parent.parentNode.insertBefore(hint, parent.nextSibling);
    }

    var emptyConfig = {
        'No patients yet.'         : { icon: 'bi-people',          msg: 'No patients yet',       hint: 'Add your first patient to get started.' },
        'No appointments found.'   : { icon: 'bi-calendar-x',      msg: 'No appointments found', hint: 'Try adjusting your filters.' },
        'No bills found.'          : { icon: 'bi-receipt',         msg: 'No bills found',        hint: 'Bills will appear here once created.' },
        'No dental records found.' : { icon: 'bi-journal-medical', msg: 'No dental records',     hint: 'No treatment records have been added yet.' },
        'No logs found.'           : { icon: 'bi-shield-check',    msg: 'No activity yet',       hint: 'Audit logs will appear here as actions are taken.' },
        'No users found.'          : { icon: 'bi-person-x',        msg: 'No users found',        hint: 'No users match your search.' }
    };
    document.querySelectorAll('td[colspan]').forEach(function (td) {
        var text = td.textContent.trim();
        var isSearch = text.indexOf('No results for') === 0;
        var cfg = null;
        if (!isSearch) {
            Object.keys(emptyConfig).forEach(function (key) {
                if (text === key || text.replace('.','') === key.replace('.','')) cfg = emptyConfig[key];
            });
        }
        if (cfg || isSearch) {
            var icon = cfg ? cfg.icon : 'bi-search';
            var msg  = isSearch ? 'No results found' : cfg.msg;
            var hint = isSearch ? 'Try a different search term.' : cfg.hint;
            td.style.cssText = 'text-align:center;padding:48px 24px;';
            td.innerHTML =
                '<div style="display:inline-flex;flex-direction:column;align-items:center;gap:10px;max-width:320px;">' +
                '<div style="width:52px;height:52px;border-radius:14px;background:var(--gray-100);display:flex;align-items:center;justify-content:center;">' +
                '<i class="bi ' + icon + '" style="font-size:1.5rem;color:var(--gray-400);"></i></div>' +
                '<div><div style="font-weight:600;font-size:0.9rem;color:var(--gray-700);margin-bottom:4px;">' + msg + '</div>' +
                '<div style="font-size:0.8rem;color:var(--gray-400);line-height:1.5;">' + hint + '</div></div></div>';
        }
    });

    var titleBlock = document.querySelector('.topbar-title-block');
    if (titleBlock && !titleBlock.querySelector('#breadcrumb')) {
        var path = window.location.pathname;
        var crumbs = ['Home'];
        if (path.indexOf('/modules/') !== -1) {
            var parts = path.split('/');
            var modIdx = parts.indexOf('modules');
            if (modIdx !== -1 && parts[modIdx + 1]) {
                crumbs.push(parts[modIdx + 1].charAt(0).toUpperCase() + parts[modIdx + 1].slice(1));
            }
        }
        var pageTitle = document.querySelector('.page-title');
        var titleText = pageTitle ? pageTitle.textContent.trim() : '';
        if (crumbs.length === 1 || crumbs[crumbs.length-1].toLowerCase() !== titleText.toLowerCase()) crumbs.push(titleText);
        if (crumbs.length > 1) {
            var bc = document.createElement('div');
            bc.id = 'breadcrumb';
            bc.innerHTML = crumbs.map(function(c,i) {
                return i === crumbs.length-1
                    ? '<span class="bc-current">' + c + '</span>'
                    : '<span class="bc-item">' + c + '</span>';
            }).join('<span class="bc-sep"><i class="bi bi-chevron-right"></i></span>');
            titleBlock.appendChild(bc);
        }
    }
}

// ─── PJAX ───────────────────────────────────────────────────────────────────
(function () {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    var bar = document.createElement('div');
    bar.id = 'pjax-bar';
    bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,#4f8ef7,#38c6b0);z-index:99999;transition:width 0.25s ease,opacity 0.3s ease;pointer-events:none;opacity:0';
    document.body.appendChild(bar);

    function barStart() { bar.style.opacity='1'; bar.style.width='60%'; }
    function barDone() {
        bar.style.width = '100%';
        setTimeout(function() { bar.style.opacity='0'; setTimeout(function(){ bar.style.width='0'; },300); }, 200);
    }

    function updateActiveLink(url) {
        sidebar.querySelectorAll('a[href]').forEach(function(a) {
            a.classList.toggle('active', a.href.split('?')[0] === url.split('?')[0]);
        });
    }

    function pjaxLoad(url, pushState) {
        barStart();
        fetch(url, { headers: { 'X-Requested-With': 'pjax' }, credentials: 'same-origin' })
        .then(function(res) {
            if (res.redirected && res.url.indexOf('index.php') !== -1) {
                window.location.href = res.url; return null;
            }
            return res.text();
        })
        .then(function(html) {
            if (!html) return;

            var parser  = new DOMParser();
            var doc     = parser.parseFromString(html, 'text/html');
            var newMain = doc.querySelector('.main-content');
            var curMain = document.querySelector('.main-content');
            if (!newMain || !curMain) { window.location.href = url; return; }

            // Destroy Chart.js instances
            if (window.Chart) {
                curMain.querySelectorAll('canvas').forEach(function(canvas) {
                    var ch = Chart.getChart ? Chart.getChart(canvas) : (Chart.instances && Chart.instances[canvas.id]);
                    if (ch) try { ch.destroy(); } catch(e) {}
                });
            }

            // Swap content
            curMain.innerHTML = newMain.innerHTML;

            // Inject page-specific styles
            document.querySelectorAll('style[data-pjax],link[data-pjax]').forEach(function(s){ s.remove(); });
            doc.querySelectorAll('head style').forEach(function(style) {
                var el = document.createElement('style');
                el.setAttribute('data-pjax','1');
                el.textContent = style.textContent;
                document.head.appendChild(el);
            });
            doc.querySelectorAll('head link[rel="stylesheet"]').forEach(function(link) {
                var href = link.getAttribute('href');
                if (!href || document.querySelector('link[href="'+href+'"]')) return;
                var el = document.createElement('link');
                el.rel='stylesheet'; el.href=href; el.setAttribute('data-pjax','1');
                document.head.appendChild(el);
            });

            // Update title + URL
            if (doc.title) document.title = doc.title;
            if (pushState !== false) history.pushState({ pjax:true, url:url }, doc.title||'', url);
            curMain.scrollTo({ top: 0 });
            var oldBc = document.getElementById('breadcrumb');
            if (oldBc) oldBc.remove();

            // ── KEY FIX: load missing external <head> scripts first ──────────
            // then run inline scripts (fixes Chart.js, any CDN libraries)
            var missing = [];
            doc.querySelectorAll('head script[src]').forEach(function(s) {
                var src = s.getAttribute('src');
                if (src && !document.querySelector('script[src="'+src+'"]')) missing.push(src);
            });

            function runInline() {
                curMain.querySelectorAll('script').forEach(function(old) {
                    var ns = document.createElement('script');
                    Array.from(old.attributes).forEach(function(a){ ns.setAttribute(a.name,a.value); });
                    ns.textContent = old.textContent;
                    old.replaceWith(ns);
                });
                initPage();
                updateActiveLink(url);
                barDone();
            }

            if (missing.length === 0) {
                runInline();
                return;
            }

            // Load each missing script, then run inline scripts
            var done = 0;
            missing.forEach(function(src) {
                var el = document.createElement('script');
                el.src = src;
                el.setAttribute('data-pjax','1');
                el.onload = el.onerror = function() {
                    done++;
                    if (done === missing.length) runInline();
                };
                document.head.appendChild(el);
            });
        })
        .catch(function() { window.location.href = url; });
    }

    sidebar.addEventListener('click', function(e) {
        var link = e.target.closest('a[href]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || href==='#' || href.startsWith('javascript')) return;
        if (href.indexOf('index.php')!==-1 || link.target==='_blank') return;
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        var url = link.href;
        if (url.split('?')[0] === window.location.href.split('?')[0]) return;
        pjaxLoad(url, true);
    });

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.pjax) pjaxLoad(e.state.url, false);
        else window.location.reload();
    });

    history.replaceState({ pjax:true, url:window.location.href }, document.title, window.location.href);
})();

// ─── ONE-TIME SETUP ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    if (sidebar) {
        var backdrop = document.createElement('div');
        backdrop.id = 'sidebar-backdrop';
        backdrop.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:999;cursor:pointer;';
        document.body.appendChild(backdrop);
        var obs = new MutationObserver(function() {
            backdrop.style.display = (window.innerWidth<=768 && sidebar.classList.contains('mobile-open')) ? 'block':'none';
        });
        obs.observe(sidebar, { attributes:true, attributeFilter:['class'] });
        backdrop.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
            backdrop.style.display = 'none';
        });
    }

    document.addEventListener('keydown', function(e) {
        var tag = document.activeElement ? document.activeElement.tagName : '';
        var inInput = (tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT'||document.activeElement.isContentEditable);
        if (e.key==='/' && !inInput && !e.ctrlKey && !e.metaKey) {
            var s = document.querySelector('input[name="search"],input[type="search"],input[placeholder*="earch"]');
            if (s) { e.preventDefault(); s.focus(); s.select(); }
        }
        if ((e.key==='n'||e.key==='N') && !inInput && !e.ctrlKey && !e.metaKey) {
            var btn = document.querySelector('a.btn-primary[href*="add"],a.btn-primary[href*="create"],a.btn-primary[href*="book"]');
            if (btn) { e.preventDefault(); btn.click(); }
        }
    });

    initPage();
});

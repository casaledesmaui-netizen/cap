// ============================================================
// PJAX — Instant sidebar navigation (no full page reloads)
// ============================================================
(function () {
    var cache = {};

    function pjaxGo(url) {
        NProgress.start();

        function swap(html) {
            var parser  = new DOMParser();
            var doc     = parser.parseFromString(html, 'text/html');
            var newBody = doc.querySelector('.page-content');
            var curBody = document.querySelector('.page-content');

            if (!newBody || !curBody) {
                window.location.href = url;
                return;
            }

            // Swap content + title + URL
            curBody.innerHTML = newBody.innerHTML;
            document.title    = doc.title;
            history.pushState({ pjax: true }, doc.title, url);

            // Update active link in sidebar
            document.querySelectorAll('#sidebar a').forEach(function (a) {
                var href = a.getAttribute('href') || '';
                a.classList.toggle('active', url.indexOf(href) !== -1 && href.length > 1);
            });

            // Re-run any inline <script> tags in the new content
            curBody.querySelectorAll('script').forEach(function (s) {
                var ns = document.createElement('script');
                if (s.src) { ns.src = s.src; ns.async = false; }
                else { ns.textContent = s.textContent; }
                s.replaceWith(ns);
            });

            window.scrollTo(0, 0);
            NProgress.done();
        }

        // Serve from cache instantly, refresh in background
        if (cache[url]) {
            swap(cache[url]);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (h) { cache[url] = h; });
            return;
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error(r.status);
                return r.text();
            })
            .then(function (html) {
                cache[url] = html;
                swap(html);
            })
            .catch(function () {
                NProgress.done();
                window.location.href = url; // fallback: normal load
            });
    }

    // Prefetch + cache on hover so click is already loaded
    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest('#sidebar a[href]');
        if (!a) return;
        var href = a.href;
        if (!href || href.includes('logout') || href.includes('#') || cache[href]) return;
        fetch(href, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (h) { cache[href] = h; });
    });

    // Intercept sidebar clicks
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#sidebar a[href]');
        if (!a) return;
        var href = a.href;
        if (!href || href.includes('logout') || href.includes('#')) return;
        if (href === window.location.href) return;
        e.preventDefault();
        pjaxGo(href);
    });

    // Handle browser back / forward buttons
    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.pjax) pjaxGo(window.location.href);
    });
})();

// Global CSRF injection: wraps fetch() so every same-origin non-GET request
// carries the session token from <meta name="csrf-token"> (rendered by the
// dashboard shells). Zero changes needed at individual call sites.
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta || window.__csrfPatched) return;
    window.__csrfPatched = true;
    var token = meta.getAttribute('content');
    var orig = window.fetch;
    window.fetch = function (input, init) {
        init = init || {};
        var url = (input instanceof Request) ? input.url : String(input);
        var method = (init.method || (input instanceof Request ? input.method : 'GET') || 'GET').toUpperCase();
        // Same-origin guard: never attach the token to cross-origin requests
        var sameOrigin = true;
        try { sameOrigin = new URL(url, window.location.href).origin === window.location.origin; } catch (e) {}
        if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
            var h = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            h.set('X-CSRF-Token', token);
            init.headers = h;
        }
        return orig.call(this, input, init);
    };
})();
/* Mini Metrics Lite tracking script. No cookies, nothing stored in the browser. */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script || !script.src) return;
    var endpoint = script.src.replace(/[^\/?#]*([?#].*)?$/, 'track.php');
    var lastPath = null;

    function send() {
        var path = location.pathname;
        if (path === lastPath) return;
        lastPath = path;

        var body = JSON.stringify({ url: location.hostname, path: path, referrer: document.referrer || '' });
        // text/plain keeps it a "simple" request: no extra preflight request
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }))) return;
        } catch (e) {}
        if (window.fetch) {
            fetch(endpoint, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } })['catch'](function () {});
        }
    }

    function track() {
        // A page the browser loads ahead of time only counts once someone opens it
        if (document.prerendering) {
            document.addEventListener('prerenderingchange', send, { once: true });
        } else {
            send();
        }
    }

    track();

    // Single-page apps: count each new address
    ['pushState', 'replaceState'].forEach(function (name) {
        var original = history[name];
        if (!original) return;
        history[name] = function () {
            var result = original.apply(this, arguments);
            track();
            return result;
        };
    });
    window.addEventListener('popstate', track);

    // Back/forward to a page the browser kept in memory counts as a new view
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            lastPath = null;
            track();
        }
    });
})();

/*
 * Webcare - anonymous "enquiry action" and visitor counting.
 *
 * Clicks: counts clicks on phone (tel:), email (mailto:) and online-booking links. It sends only
 * the TYPE of click ("phone", "email" or "booking") - never the number, address, page or
 * anything about the visitor.
 * Page views: sends one "pageview" per new page visit with just the page's path (printed into the
 * page by the website, so no search words and no "#" part), a code proving the path is genuine, and
 * the host name of the website the visitor came from. Reloads and back/forward are not counted.
 * The only part of the visitor's address that is ever read is utm_source, and only to spot AI
 * assistants (ChatGPT and similar).
 * No cookies and nothing is stored on the visitor's device. Totals are added up on the server.
 *
 * Settings come from the page as window.webcareTrack:
 *   endpoint - the address to send the count to
 *   booking  - list of booking sites, e.g. "cliniko.com" or "example.co.uk/book"
 *   clicks   - false switches click counting off
 *   visits   - true switches page-view counting on
 *   is404, isSearch - true on error pages / search results, which are not counted as page views
 *   path, sig - this page's path and its proof code, both printed by the server
 *
 * This file must never get in the way of a visitor, so everything is wrapped in try/catch
 * and nothing here delays or cancels the click.
 */
(function () {
    'use strict';

    /* ---- Working out what kind of link it is ---- */

    // Does this link's web address match one entry of the booking list?
    // An entry is a site name ("cliniko.com", which also matches "clinic.cliniko.com"),
    // optionally followed by a folder ("example.co.uk/book").
    function matchesBookingRule(hostname, pathname, rule) {
        var slash = rule.indexOf('/');
        var ruleHost = (slash === -1 ? rule : rule.substring(0, slash)).toLowerCase();
        var rulePath = slash === -1 ? '' : rule.substring(slash).toLowerCase().replace(/\/+$/, '');

        ruleHost = ruleHost.replace(/^www\./, '');
        if (ruleHost === '') {
            return false;
        }

        var isSubdomain = hostname.length > ruleHost.length + 1 &&
            hostname.substring(hostname.length - ruleHost.length - 1) === '.' + ruleHost;
        var hostOk = (hostname === ruleHost) || isSubdomain;
        if (!hostOk) {
            return false;
        }

        if (rulePath === '') {
            return true;
        }
        var path = ('/' + String(pathname || '').replace(/^\/+/, '')).toLowerCase().replace(/\/+$/, '');
        return path === rulePath || path.indexOf(rulePath + '/') === 0;
    }

    // Returns "phone", "email", "booking" or "" (not one we count).
    // protocol is like "tel:" or "https:", hostname like "www.cliniko.com", pathname like "/book".
    function classify(protocol, hostname, pathname, bookingRules) {
        protocol = String(protocol || '').toLowerCase();

        if (protocol === 'tel:') {
            return 'phone';
        }
        if (protocol === 'mailto:') {
            return 'email';
        }
        if (protocol !== 'http:' && protocol !== 'https:') {
            return '';
        }

        var host = String(hostname || '').toLowerCase().replace(/^www\./, '');
        if (host === '' || !bookingRules || !bookingRules.length) {
            return '';
        }
        // Patient sign-in links ("…janeapp.co.uk/login") are for managing existing bookings,
        // not making a new one, so they aren't counted as booking clicks.
        var firstPart = String(pathname || '').toLowerCase().replace(/^\/+/, '').split('/')[0];
        if (firstPart === 'login' || firstPart === 'signin' || firstPart === 'sign_in' || firstPart === 'account' || firstPart === 'my-account') {
            return '';
        }
        for (var i = 0; i < bookingRules.length; i++) {
            if (typeof bookingRules[i] === 'string' && matchesBookingRule(host, pathname, bookingRules[i])) {
                return 'booking';
            }
        }
        return '';
    }

    /* ---- Sending the count ---- */

    // "extra" is an optional object of more text fields (used by page views: path and ref).
    function send(endpoint, type, extra) {
        var data = new FormData();
        data.append('type', type);
        if (extra) {
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    data.append(key, String(extra[key]));
                }
            }
        }

        // sendBeacon is built for this: it keeps going even while the page is changing.
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, data)) {
            return;
        }
        if (window.fetch) {
            window.fetch(endpoint, {
                method: 'POST',
                body: data,
                keepalive: true,
                credentials: 'omit'
            }).catch(function () {});
        }
    }

    /* ---- Page views ---- */

    // The website name of the page the visitor came from, e.g. "www.google.com", or "" if they
    // typed the address, used a bookmark, or their browser hides it. Nothing else of the
    // referring address (path, search words) is ever read or sent.
    // A visitor who comes from an Android app gets "android-app:" plus the app's package name.
    function referrerHost() {
        var ref = document.referrer;
        if (!ref || typeof ref !== 'string') {
            return '';
        }
        var app = /^android-app:\/\/([a-z0-9_.]+)/i.exec(ref);
        if (app) {
            return 'android-app:' + app[1].toLowerCase();
        }
        var link = document.createElement('a');
        link.href = ref;
        if (link.protocol !== 'http:' && link.protocol !== 'https:') {
            return '';
        }
        return String(link.hostname || '').toLowerCase();
    }

    // Some AI assistants send visitors with no referrer but tag the link with ?utm_source=...
    // Only that one setting is read, and only if it is one of these names; it is otherwise ignored.
    var AI_SOURCES = ['chatgpt.com', 'chatgpt', 'openai', 'perplexity', 'perplexity.ai', 'claude.ai', 'gemini', 'copilot'];
    function utmSource() {
        var query = String(window.location.search || '').replace(/^\?/, '');
        var pairs = query.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var eq = pairs[i].indexOf('=');
            if (eq === -1 || pairs[i].substring(0, eq).toLowerCase() !== 'utm_source') {
                continue;
            }
            var value = '';
            try {
                value = decodeURIComponent(pairs[i].substring(eq + 1).replace(/\+/g, ' ')).toLowerCase();
            } catch (e) {
                return '';
            }
            for (var j = 0; j < AI_SOURCES.length; j++) {
                if (AI_SOURCES[j] === value) {
                    return value;
                }
            }
            return '';
        }
        return '';
    }

    // Was this page opened with "reload" or the browser's back/forward buttons? Those are the same
    // visitor looking at the same page again, so they are not counted as a new page view.
    function isReloadOrBack() {
        try {
            var perf = window.performance;
            if (perf && typeof perf.getEntriesByType === 'function') {
                var entries = perf.getEntriesByType('navigation');
                if (entries && entries.length && typeof entries[0].type === 'string') {
                    return entries[0].type === 'reload' || entries[0].type === 'back_forward';
                }
            }
            // Older browsers: 1 = reload, 2 = back/forward.
            if (perf && perf.navigation && typeof perf.navigation.type === 'number') {
                return perf.navigation.type === 1 || perf.navigation.type === 2;
            }
        } catch (e) {
            // Unknown: count it.
        }
        return false;
    }

    // Sends one page view: the page's path and its code exactly as the website printed them into
    // the page (the visitor's own address bar is never read for the path), the referring host,
    // and, only for the AI names above, utm_source.
    function sendPageview(config) {
        var fields = { path: config.path, sig: config.sig, ref: referrerHost() };
        var utm = utmSource();
        if (utm !== '') {
            fields.utm = utm;
        }
        send(config.endpoint, 'pageview', fields);
    }

    // Counts this page load once. The page is not counted again when a visitor goes "back" to it
    // from the browser's page cache: that page is not reloaded, so none of this runs again.
    function startPageviews(config) {
        // The server decides what the page is; error pages and search results are never counted.
        // A page with no path and code (an old cached copy of the page) sends nothing.
        if (config.visits !== true || config.is404 === true || config.isSearch === true) {
            return;
        }
        if (typeof config.path !== 'string' || config.path === '' || typeof config.sig !== 'string' || config.sig === '') {
            return;
        }
        if (isReloadOrBack()) {
            return;
        }
        var done = false;
        function once() {
            if (done) {
                return;
            }
            done = true;
            try {
                sendPageview(config);
            } catch (e) {
                // Never let counting cause a problem for the visitor.
            }
        }
        function whenLoaded() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', once);
            } else {
                once();
            }
        }
        // A page the browser loads in the background ("prerender") is only counted if it is shown.
        if (document.prerendering === true) {
            document.addEventListener('prerenderingchange', whenLoaded);
        } else {
            whenLoaded();
        }
    }

    /* ---- Listening for clicks ---- */

    function start() {
        var config = window.webcareTrack;
        if (!config || typeof config.endpoint !== 'string' || config.endpoint === '') {
            return;
        }

        try {
            startPageviews(config);
        } catch (e) {
            // Ignore - see above.
        }

        // Click counting can be switched off separately (config.clicks === false).
        if (config.clicks === false) {
            return;
        }
        var rules = (config.booking && config.booking.length) ? config.booking : [];

        // The same kind of click within one second is counted once (double-clicks, double taps).
        var lastType = '';
        var lastTime = 0;

        function handle(event) {
            try {
                // Only real clicks by a person. Clicks made by a script (for example
                // link.click() in some other plugin) are not enquiries.
                if (event.isTrusted === false) {
                    return;
                }

                // Left click ("click") or middle click ("auxclick" with button 1) only.
                // Right-clicks are not a visit to the link.
                if (event.type === 'auxclick' && event.button !== 1) {
                    return;
                }

                // Find the link that was clicked (it may be an image or icon inside a link).
                var el = event.target;
                while (el && el !== document) {
                    if (el.tagName && String(el.tagName).toLowerCase() === 'a' && el.getAttribute('href')) {
                        break;
                    }
                    el = el.parentNode;
                }
                if (!el || el === document || typeof el.protocol !== 'string') {
                    return;
                }

                var type = classify(el.protocol, el.hostname, el.pathname, rules);
                if (type === '') {
                    return;
                }

                var now = new Date().getTime();
                if (type === lastType && now - lastTime < 1000) {
                    return;
                }
                lastType = type;
                lastTime = now;

                send(config.endpoint, type);
            } catch (e) {
                // Never let counting cause a problem for the visitor.
            }
        }

        // Capture phase, so we still hear the click if another script stops it spreading.
        document.addEventListener('click', handle, true);
        document.addEventListener('auxclick', handle, true);
    }

    try {
        start();
    } catch (e) {
        // Ignore - see above.
    }
}());

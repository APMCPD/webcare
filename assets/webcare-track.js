/*
 * Webcare - anonymous "enquiry action" counting.
 *
 * Counts clicks on phone (tel:), email (mailto:) and online-booking links. It sends only
 * the TYPE of click ("phone", "email" or "booking") - never the number, address, page or
 * anything about the visitor. No cookies, no storage. Totals are added up on the server.
 *
 * Settings come from the page as window.webcareTrack:
 *   endpoint - the address to send the count to
 *   booking  - list of booking sites, e.g. "cliniko.com" or "example.co.uk/book"
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

    function send(endpoint, type) {
        var data = new FormData();
        data.append('type', type);

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

    /* ---- Listening for clicks ---- */

    function start() {
        var config = window.webcareTrack;
        if (!config || typeof config.endpoint !== 'string' || config.endpoint === '') {
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

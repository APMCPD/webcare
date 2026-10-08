<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Visits and page views: simple, privacy-friendly website visitor numbers for the quarterly report.
 *
 * What is stored: monthly totals only (page views, visits, where visits came from, which kind of
 * device) plus a list of the most-viewed page addresses. Nothing is stored per visitor, there are
 * no cookies and nothing is kept on the visitor's device. The only thing tied to a visitor's
 * connection is the temporary scrambled code used for the spam limits (see enquiry-tracking.php),
 * which is discarded after 10 minutes.
 *
 * Like the enquiry counting, this runs on the public side of every client site, so everything is
 * defensive and, if anything goes wrong, it simply does nothing.
 */

/* ------------------------------------------------------------------
 * Fixed lists and on/off switch
 * ---------------------------------------------------------------- */

// Can be switched off on one site (separately from the enquiry counting):
//   add_filter( 'webcare_track_visits', '__return_false' );
function webcare_visits_enabled() {
    return (bool) apply_filters( 'webcare_track_visits', true );
}

// Where a visit came from. Only these five values are ever stored.
function webcare_visit_sources() {
    return [ 'search', 'ai', 'social', 'other', 'direct' ];
}

function webcare_visit_devices() {
    return [ 'mobile', 'tablet', 'desktop' ];
}

// The most page addresses we keep per month. More than a quarterly "top 20" ever needs.
function webcare_visit_pages_cap() {
    return 200;
}

/* ------------------------------------------------------------------
 * Cleaning what the browser sends
 * ---------------------------------------------------------------- */

// Turns the page address sent by the browser into a safe, tidy path such as "/about/team".
// Returns '' if it can't be trusted (not text, doesn't start with "/", or contains anything other
// than plain printable ASCII: browsers always send addresses with other characters
// percent-encoded, so spaces, control characters and raw non-English bytes mean it is not real).
// Any query string or "#" part is removed, repeated slashes are collapsed, the
// trailing slash is dropped (except for the home page) and the result is cut to 200 characters.
// If the site lives in a sub-folder that folder stays in the path as it is.
function webcare_normalise_path( $raw ) {
    if ( ! is_string( $raw ) ) {
        return '';
    }
    // Remove any query string / "#" part first (never trust it was already removed), so an odd
    // byte in a query string can't cause the page itself to be skipped.
    foreach ( [ '?', '#' ] as $cut ) {
        $pos = strpos( $raw, $cut );
        if ( false !== $pos ) {
            $raw = substr( $raw, 0, $pos );
        }
    }
    // Then cut, so the check below never has to look at a huge string.
    $raw = substr( $raw, 0, 2000 );
    if ( 1 !== preg_match( '/^[\x21-\x7E]+$/', (string) $raw ) ) {
        return '';
    }
    if ( '' === $raw || '/' !== $raw[0] ) {
        return '';
    }

    $path = preg_replace( '#/{2,}#', '/', $raw );
    if ( ! is_string( $path ) ) {
        return '';
    }
    $path = substr( $path, 0, 200 );
    if ( strlen( $path ) > 1 ) {
        $path = rtrim( $path, '/' );
        if ( '' === $path ) {
            $path = '/';
        }
    }
    return $path;
}

// The referring website's host name ("www.google.com"), lower case, from whatever the browser
// sent (the script sends just the host; a full address is tolerated). '' means "no referrer".
// A visit that came from an Android app arrives as "android-app:com.example.app" (the app's
// package name); it is never a website, so it can never look like this site.
function webcare_clean_ref_host( $raw ) {
    if ( ! is_string( $raw ) ) {
        return '';
    }
    $raw = strtolower( trim( substr( $raw, 0, 300 ) ) );
    if ( '' === $raw ) {
        return '';
    }
    if ( 0 === strpos( $raw, 'android-app:' ) ) {
        if ( 1 === preg_match( '#^android-app:(?://)?([a-z0-9_.]{1,100})(?:[/?\#]|$)#', $raw, $m ) ) {
            return 'android-app:' . $m[1];
        }
        return '';
    }
    if ( false !== strpos( $raw, '/' ) ) {
        $host = wp_parse_url( $raw, PHP_URL_HOST );
        $raw  = is_string( $host ) ? $host : '';
    }
    $raw = rtrim( $raw, '.' );
    if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9.\-]{0,98}[a-z0-9])?$/', $raw ) ) {
        return '';
    }
    return $raw;
}

// Is this host the website itself (with or without "www.")?
function webcare_is_own_host( $host ) {
    if ( ! is_string( $host ) || '' === $host ) {
        return false;
    }
    if ( 0 === strpos( $host, 'www.' ) ) {
        $host = substr( $host, 4 );
    }
    return in_array( $host, [ webcare_plain_host( home_url( '/' ) ), webcare_plain_host( site_url( '/' ) ) ], true );
}

/* ------------------------------------------------------------------
 * Where did the visit come from?
 * ---------------------------------------------------------------- */

// Is the part of a host name after a brand name a normal ending, like "com", "de", "co.uk"
// or "com.au"? $rest is the list of labels after the brand.
function webcare_is_domain_ending( $rest ) {
    if ( 1 === count( $rest ) ) {
        return 1 === preg_match( '/^[a-z]{2,6}$/', $rest[0] );
    }
    if ( 2 === count( $rest ) ) {
        return in_array( $rest[0], [ 'co', 'com', 'org', 'net', 'ac', 'gov', 'edu' ], true )
            && 1 === preg_match( '/^[a-z]{2}$/', $rest[1] );
    }
    return false;
}

// Does the host match one entry of a list? Matching is always on whole parts of the name, so
// look-alikes such as "notgoogle.com" or "google.evil.com" never match. Two kinds of entry:
//   "t.co"     - that site and any of its sub-sites (l.facebook.com matches "facebook.com").
//   "bing."    - that brand with any normal country ending (bing.com, google.co.uk), and any
//                sub-site of it. The brand may be more than one part: "search.yahoo." matches
//                search.yahoo.com, uk.search.yahoo.com and search.yahoo.co.jp, but not mail.yahoo.com.
//   "^google." - same, but only the bare name or "www." in front (so mail.google.com is not search).
function webcare_host_matches_rule( $host, $rule ) {
    if ( ! is_string( $host ) || ! is_string( $rule ) || '' === $host || '' === $rule ) {
        return false;
    }

    if ( '.' !== substr( $rule, -1 ) ) {
        $len = strlen( $rule );
        return $host === $rule || ( strlen( $host ) > $len + 1 && substr( $host, -( $len + 1 ) ) === '.' . $rule );
    }

    $strict = ( '^' === $rule[0] );
    $brand  = explode( '.', rtrim( ltrim( $rule, '^' ), '.' ) );
    $count  = count( $brand );
    $labels = explode( '.', $host );
    for ( $i = 0; $i + $count <= count( $labels ); $i++ ) {
        if ( array_slice( $labels, $i, $count ) !== $brand ) {
            continue;
        }
        if ( $strict && ! ( 0 === $i || ( 1 === $i && 'www' === $labels[0] ) ) ) {
            continue;
        }
        if ( webcare_is_domain_ending( array_slice( $labels, $i + $count ) ) ) {
            return true;
        }
    }
    return false;
}

// The only "utm_source" values the script may pass on. AI assistants such as ChatGPT often send
// visitors without a referrer but tag the link with one of these, so they can be told apart
// from people who typed the address.
function webcare_ai_utm_sources() {
    return [ 'chatgpt.com', 'chatgpt', 'openai', 'perplexity', 'perplexity.ai', 'claude.ai', 'gemini', 'copilot' ];
}

// The utm_source value if it is one of the allowed AI names, otherwise ''.
function webcare_clean_utm( $raw ) {
    if ( ! is_string( $raw ) ) {
        return '';
    }
    $raw = strtolower( trim( substr( $raw, 0, 50 ) ) );
    return in_array( $raw, webcare_ai_utm_sources(), true ) ? $raw : '';
}

// 'search', 'ai', 'social', 'other' (any other website) or 'direct' (no referrer) for a referring host.
// AI assistants are checked before search engines (gemini.google.com is AI, not search).
// Bing's AI chat can't be told apart from Bing search, so Bing counts as search.
//
// $ref_host may also be "android-app:<package>": only the Google search app counts as search; any
// other app (Gmail and so on) is "other".
// $utm is an allowed AI "utm_source" (see webcare_clean_utm()). It turns a visit that would
// otherwise be "direct" or "other" into "ai"; it never overrides search or social.
function webcare_visit_source( $ref_host, $utm = '' ) {
    $ref_host = is_string( $ref_host ) ? strtolower( $ref_host ) : '';
    $source   = webcare_visit_source_from_host( $ref_host );

    if ( ( 'direct' === $source || 'other' === $source ) && '' !== webcare_clean_utm( $utm ) ) {
        return 'ai';
    }
    return $source;
}

function webcare_visit_source_from_host( $ref_host ) {
    if ( '' === $ref_host ) {
        return 'direct';
    }
    if ( 0 === strpos( $ref_host, 'android-app:' ) ) {
        return ( 'android-app:com.google.android.googlequicksearchbox' === $ref_host ) ? 'search' : 'other';
    }

    $lists = [
        'ai'     => [ 'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'duck.ai', 'chat.mistral.ai', 'meta.ai' ],
        'search' => [ '^google.', 'bing.', 'duckduckgo.', 'search.yahoo.', 'ecosia.', 'yandex.', 'baidu.', 'startpage.', 'qwant.', 'search.brave.com' ],
        'social' => [ 'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'lnkd.in', 'twitter.com', 'x.com', 't.co', 'tiktok.com', 'youtube.com', 'pinterest.', 'threads.net', 'nextdoor.', 'reddit.com', 'bsky.app' ],
    ];
    foreach ( $lists as $source => $rules ) {
        foreach ( $rules as $rule ) {
            if ( webcare_host_matches_rule( $ref_host, $rule ) ) {
                return $source;
            }
        }
    }
    return 'other';
}

/* ------------------------------------------------------------------
 * What kind of device?
 * ---------------------------------------------------------------- */

// 'tablet', 'mobile' or 'desktop', guessed from the browser name the visitor's device sends.
// Good enough for "roughly how many people use a phone". (An iPad set to "desktop site" looks like
// a Mac and is counted as desktop.)
function webcare_device_from_user_agent( $user_agent ) {
    $ua = is_string( $user_agent ) ? substr( $user_agent, 0, 500 ) : '';
    if ( 1 === preg_match( '/ipad|tablet|kindle|silk\/|playbook|nexus 7|nexus 9|sm-t\d/i', $ua ) ) {
        return 'tablet';
    }
    // Android phones say "Mobile"; Android tablets don't.
    if ( 1 === preg_match( '/android/i', $ua ) ) {
        return ( 1 === preg_match( '/mobile|opera mini/i', $ua ) ) ? 'mobile' : 'tablet';
    }
    if ( 1 === preg_match( '/mobi|iphone|ipod|windows phone|blackberry|opera mini/i', $ua ) ) {
        return 'mobile';
    }
    return 'desktop';
}

/* ------------------------------------------------------------------
 * Storing and reading the totals
 * ---------------------------------------------------------------- */

// One option per month, e.g. webcare_visits_2026_10.
function webcare_visits_option_name( $month_key ) {
    return 'webcare_visits_' . $month_key;
}

// Any stored value turned into a safe, complete set of totals.
function webcare_normalise_visit_stats( $value ) {
    $value = is_array( $value ) ? $value : [];
    $int   = function ( $v ) {
        $n = is_numeric( $v ) ? (int) $v : 0;
        return ( $n > 0 ) ? $n : 0;
    };

    $out = [
        'views'   => isset( $value['views'] ) ? $int( $value['views'] ) : 0,
        'visits'  => isset( $value['visits'] ) ? $int( $value['visits'] ) : 0,
        'sources' => [],
        'devices' => [],
        'pages'   => [],
        // true if, in this month, some page views were turned away by the spam limits.
        'capped'  => ! empty( $value['capped'] ),
    ];
    foreach ( webcare_visit_sources() as $key ) {
        $out['sources'][ $key ] = ( isset( $value['sources'] ) && is_array( $value['sources'] ) && isset( $value['sources'][ $key ] ) ) ? $int( $value['sources'][ $key ] ) : 0;
    }
    foreach ( webcare_visit_devices() as $key ) {
        $out['devices'][ $key ] = ( isset( $value['devices'] ) && is_array( $value['devices'] ) && isset( $value['devices'][ $key ] ) ) ? $int( $value['devices'][ $key ] ) : 0;
    }
    if ( isset( $value['pages'] ) && is_array( $value['pages'] ) ) {
        foreach ( $value['pages'] as $path => $views ) {
            $n = $int( $views );
            if ( $n > 0 ) {
                $out['pages'][ (string) $path ] = $n;
            }
        }
    }
    return $out;
}

// Remembers the day the first page view was recorded (once), for "counted since" on the admin page.
// Separate from the enquiry date ('webcare_tracking_started'), because page views started later.
function webcare_ensure_visits_started() {
    if ( false === get_option( 'webcare_visits_started', false ) ) {
        $today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        add_option( 'webcare_visits_started', is_string( $today ) ? $today : gmdate( 'Y-m-d' ), '', 'yes' );
    }
}

// Notes on this month's totals that some page views were turned away by the spam limits, so the
// Webcare page can say the figures may be a little low. Written at most once a month: if the note
// is already there, nothing is saved.
function webcare_mark_visits_capped() {
    try {
        $option = webcare_visits_option_name( webcare_stats_month_key() );
        $stored = get_option( $option, [] );
        if ( is_array( $stored ) && ! empty( $stored['capped'] ) ) {
            return;
        }
        $stats           = webcare_normalise_visit_stats( $stored );
        $stats['capped'] = true;
        update_option( $option, $stats, false );
    } catch ( \Throwable $e ) {
        return;
    }
}

// A short code that proves a page's path was worked out by this website, not made up by a visitor.
// Each page carries its own path and code in the page itself (see webcare_enqueue_tracking), the script
// sends them back, and the server checks the code before counting. This keeps made-up addresses out of
// the "most-viewed pages" list. The code is the first 16 characters of a keyed hash of the path.
function webcare_path_signature( $path ) {
    return substr( hash_hmac( 'sha256', 'webcare-pv|' . $path, wp_salt( 'nonce' ) ), 0, 16 );
}

function webcare_path_signature_valid( $path, $sig ) {
    if ( ! is_string( $path ) || '' === $path || ! is_string( $sig ) || '' === $sig ) {
        return false;
    }
    return hash_equals( webcare_path_signature( $path ), $sig );
}

// The cleaned path of the page being viewed right now (worked out on the server from the address
// that was requested). '' if it can't be worked out.
function webcare_current_request_path() {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only ever cleaned by webcare_normalise_path() and compared; never printed.
    $uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    return webcare_normalise_path( $uri );
}

// Adds one page view (and, if the visitor arrived from outside the site, one visit) to this month.
// $path is an already cleaned path, $ref_host the referring host ('' if none), $device one of
// webcare_visit_devices(). Returns true if it was counted.
//
// A visit is a page view whose referrer is NOT this website: the visitor came from a search
// engine, another site, or typed the address / used a bookmark. A referrer that is this site means
// they simply clicked from one page to another, which counts as a page view only. Visits are
// therefore not a count of unique people.
//
// Same accepted trade-off as the enquiry totals: the month's option is read, changed and saved back,
// so two page views at exactly the same instant could count as one. Fine for small clinic sites.
function webcare_record_pageview( $path, $ref_host, $device, $utm = '' ) {
    if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
        return false;
    }
    if ( ! in_array( $device, webcare_visit_devices(), true ) ) {
        $device = 'desktop';
    }
    $ref_host = webcare_clean_ref_host( $ref_host );
    webcare_ensure_visits_started();

    $option = webcare_visits_option_name( webcare_stats_month_key() );
    $stats  = webcare_normalise_visit_stats( get_option( $option, [] ) );

    $stats['views']++;
    if ( '' === $ref_host || ! webcare_is_own_host( $ref_host ) ) {
        $stats['visits']++;
        $stats['sources'][ webcare_visit_source( $ref_host, $utm ) ]++;
        $stats['devices'][ $device ]++; // Devices are counted per visit, not per page view.
    }

    // Page list: at most 200 addresses a month. When a new address would go over the limit, the
    // address with the fewest views is dropped first. That makes the list approximate for rarely
    // viewed pages, which is fine because the report only needs the top 20.
    if ( isset( $stats['pages'][ $path ] ) ) {
        $stats['pages'][ $path ]++;
    } else {
        if ( count( $stats['pages'] ) >= webcare_visit_pages_cap() ) {
            $lowest_path  = null;
            $lowest_count = null;
            foreach ( $stats['pages'] as $existing => $count ) {
                if ( null === $lowest_count || $count < $lowest_count ) {
                    $lowest_path  = $existing;
                    $lowest_count = $count;
                }
            }
            if ( null !== $lowest_path ) {
                unset( $stats['pages'][ $lowest_path ] );
            }
        }
        $stats['pages'][ $path ] = 1;
    }

    update_option( $option, $stats, false );
    return true;
}

// Totals for every month from $from_month to $to_month inclusive (both like "2026_07"). The page
// lists are merged and sorted with the most-viewed first.
function webcare_get_visit_stats( $from_month, $to_month ) {
    $total = webcare_normalise_visit_stats( [] );
    $from  = webcare_parse_month_key( $from_month );
    $to    = webcare_parse_month_key( $to_month );
    if ( null === $from || null === $to ) {
        return $total;
    }

    $year  = $from[0];
    $month = $from[1];
    // The limit of 60 months is only a safety net against a typo causing a long loop.
    for ( $i = 0; $i < 60; $i++ ) {
        if ( ( $year * 12 + $month ) > ( $to[0] * 12 + $to[1] ) ) {
            break;
        }
        $stats = webcare_normalise_visit_stats( get_option( webcare_visits_option_name( sprintf( '%04d_%02d', $year, $month ) ), [] ) );
        $total['views']  += $stats['views'];
        $total['visits'] += $stats['visits'];
        foreach ( $stats['sources'] as $key => $n ) {
            $total['sources'][ $key ] += $n;
        }
        foreach ( $stats['devices'] as $key => $n ) {
            $total['devices'][ $key ] += $n;
        }
        foreach ( $stats['pages'] as $path => $n ) {
            $path = (string) $path;
            $total['pages'][ $path ] = ( isset( $total['pages'][ $path ] ) ? $total['pages'][ $path ] : 0 ) + $n;
        }
        if ( $stats['capped'] ) {
            $total['capped'] = true;
        }
        $month++;
        if ( $month > 12 ) {
            $month = 1;
            $year++;
        }
    }

    // Most views first; pages with the same number are put in alphabetical order so the list is steady.
    $paths = array_keys( $total['pages'] );
    usort(
        $paths,
        function ( $a, $b ) use ( $total ) {
            $diff = $total['pages'][ $b ] - $total['pages'][ $a ];
            return ( 0 !== $diff ) ? $diff : strcmp( (string) $a, (string) $b );
        }
    );
    $sorted = [];
    foreach ( $paths as $path ) {
        $sorted[ $path ] = $total['pages'][ $path ];
    }
    $total['pages'] = $sorted;

    return $total;
}

// Visit totals for the current calendar quarter (0) or an earlier/later one (-1 = last quarter).
// (The optional timestamp is only there so the quarter can be worked out for a fixed date when testing.)
function webcare_get_quarter_visits( $quarter_offset = 0, $timestamp = null ) {
    $info = webcare_quarter_info( $quarter_offset, $timestamp );
    return webcare_get_visit_stats( $info['from_month'], $info['to_month'] );
}

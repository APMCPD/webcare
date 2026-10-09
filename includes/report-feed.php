<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * The report feed: a signed, read-only address that APM's health check app uses to fetch this
 * website's monthly figures for the quarterly report.
 *
 *   GET /wp-json/webcare/v1/report?from=2026_07&to=2026_09
 *
 * Only APM can use it: every request must carry a timestamp and a signature made with this site's
 * secret "connection key" (shown only to Administrators on the Webcare page). Without a correct
 * signature the address answers "401 not authorised" and nothing else. The full written contract
 * is in docs/webcare-feed.md.
 *
 * What it gives out: monthly totals that are already shown on the Webcare page, plus a few plain
 * facts about the site (WordPress/PHP version, number of waiting updates). It never gives out the
 * connection key, and nothing about individual visitors exists to give out.
 *
 * It is read-only (it only reads settings and, at most once an hour, notes when it was last used)
 * and every part is wrapped in try/catch so a problem here can never break the website.
 */

/* ------------------------------------------------------------------
 * On/off switch and fixed numbers
 * ---------------------------------------------------------------- */

// Can be switched off on one site (the address then does not exist):
//   add_filter( 'webcare_report_feed', '__return_false' );
function webcare_report_feed_enabled() {
    return (bool) apply_filters( 'webcare_report_feed', true );
}

// A request may be this many seconds away from the website's own clock (5 minutes each way).
function webcare_feed_time_leeway() {
    return 300;
}

// The most wrong attempts one visitor connection may make per 10 minutes before being turned away.
function webcare_feed_fail_max() {
    return 20;
}

function webcare_feed_fail_window() {
    return 10 * MINUTE_IN_SECONDS;
}

// The most months one request may ask for.
function webcare_feed_max_months() {
    return 24;
}

/* ------------------------------------------------------------------
 * The connection key
 * ---------------------------------------------------------------- */

// A fresh secret: 64 hex characters (32 random bytes).
function webcare_generate_connection_key() {
    try {
        return bin2hex( random_bytes( 32 ) );
    } catch ( \Throwable $e ) {
        // No secure random source: fall back to WordPress's own generator, hashed to 64 hex characters.
        return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime() . wp_salt( 'auth' ) );
    }
}

function webcare_is_valid_connection_key( $key ) {
    return is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $key );
}

// The stored key, or '' if there isn't a usable one. With $create = true a key is made if missing
// (only the admin page does that, the first time an Administrator looks).
// Kept in its own option that is not autoloaded, so it is never loaded on ordinary page views.
function webcare_get_connection_key( $create = false ) {
    $key = get_option( 'webcare_connection_key', '' );
    if ( webcare_is_valid_connection_key( $key ) ) {
        return $key;
    }
    if ( ! $create ) {
        return '';
    }
    $new = webcare_generate_connection_key();
    if ( false === get_option( 'webcare_connection_key', false ) ) {
        add_option( 'webcare_connection_key', $new, '', 'no' );
        // If two Administrators looked at the same moment, the first one saved wins.
        $saved = get_option( 'webcare_connection_key', '' );
        return webcare_is_valid_connection_key( $saved ) ? $saved : '';
    }
    // Something unusable was stored (damaged): replace it.
    update_option( 'webcare_connection_key', $new, false );
    return $new;
}

// Replaces the key. The old one stops working immediately.
function webcare_set_new_connection_key() {
    $new = webcare_generate_connection_key();
    update_option( 'webcare_connection_key', $new, false );
    return $new;
}

// "Create a new key" button (admin_post_webcare_new_key). Administrators only, and only with the
// page's own nonce. Checked on every request, not just when the button is shown.
function webcare_handle_new_key() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorised', 403 );
    }
    check_admin_referer( 'webcare_new_key' );

    webcare_set_new_connection_key();
    webcare_redirect( 'key_renewed' );
}

/* ------------------------------------------------------------------
 * Checking who is asking
 * ---------------------------------------------------------------- */

// The text that is signed: timestamp, "GET" and the route with its query, each on its own line.
// $from and $to are used exactly as sent.
function webcare_feed_signing_string( $timestamp, $from, $to ) {
    return $timestamp . "\n" . 'GET' . "\n" . '/webcare/v1/report?from=' . $from . '&to=' . $to;
}

// The signature a correct caller would send: a lower-case hex HMAC-SHA256 of the signing string.
function webcare_feed_signature( $key, $timestamp, $from, $to ) {
    return hash_hmac( 'sha256', webcare_feed_signing_string( $timestamp, $from, $to ), $key );
}

// Is this a genuine, fresh, correctly signed request? Says only yes or no (never why not).
function webcare_feed_request_is_signed( $request ) {
    $key = webcare_get_connection_key( false );
    if ( '' === $key ) {
        return false;
    }

    $timestamp = $request->get_header( 'x_webcare_timestamp' );
    $signature = $request->get_header( 'x_webcare_signature' );
    $from      = $request->get_param( 'from' );
    $to        = $request->get_param( 'to' );

    if ( ! is_string( $timestamp ) || ! is_string( $signature ) || ! is_string( $from ) || ! is_string( $to ) ) {
        return false;
    }
    // Whole seconds only, e.g. "1790000000".
    if ( 1 !== preg_match( '/^[0-9]{1,12}\z/', $timestamp ) ) {
        return false;
    }
    if ( abs( time() - (int) $timestamp ) > webcare_feed_time_leeway() ) {
        return false;
    }
    if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}\z/', $signature ) ) {
        return false;
    }

    // hash_equals compares in constant time, so the check can't be used to guess the signature bit by bit.
    return hash_equals( webcare_feed_signature( $key, $timestamp, $from, $to ), strtolower( $signature ) );
}

function webcare_feed_fail_key() {
    return 'webcare_rf_' . webcare_visitor_hash();
}

// Read-only look: has this connection already used up its wrong attempts? (Nothing is written, so
// a flood of requests creates no new temporary rows once the limit is reached.)
function webcare_feed_is_blocked() {
    $state = get_transient( webcare_feed_fail_key() );
    return is_array( $state ) && isset( $state['n'], $state['t'] )
        && ( time() - (int) $state['t'] ) < webcare_feed_fail_window()
        && (int) $state['n'] >= webcare_feed_fail_max();
}

// Notes one wrong attempt for this connection (kept 10 minutes, then forgotten).
function webcare_feed_note_failure() {
    webcare_window_limit_reached( webcare_feed_fail_key(), webcare_feed_fail_max(), webcare_feed_fail_window() );
}

// The standard "not authorised" answer. Deliberately says nothing about what was wrong.
function webcare_feed_unauthorised() {
    return new WP_Error( 'webcare_unauthorised', __( 'Not authorised.', 'webcare' ), [ 'status' => 401 ] );
}

// Runs before the report is built. Returns true, or an error that WordPress sends instead.
function webcare_report_permission( $request ) {
    try {
        if ( ! webcare_report_feed_enabled() ) {
            return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'webcare' ), [ 'status' => 404 ] );
        }
        // The signature is checked FIRST: a correctly signed request is always served, even if wrong
        // attempts from the same connection have filled the limit. (Several visitors can share one
        // connection address, e.g. a proxy or CDN, and must not be able to lock APM out.)
        if ( webcare_feed_request_is_signed( $request ) ) {
            return true;
        }
        // Only a request that failed is limited. Once the limit is reached nothing more is written.
        if ( webcare_feed_is_blocked() ) {
            return new WP_Error( 'webcare_rate_limited', __( 'Too many attempts. Please try again later.', 'webcare' ), [ 'status' => 429 ] );
        }
        webcare_feed_note_failure();
        return webcare_feed_unauthorised();
    } catch ( \Throwable $e ) {
        return webcare_feed_unauthorised();
    }
}

/* ------------------------------------------------------------------
 * Checking what was asked for
 * ---------------------------------------------------------------- */

// Turns a month such as "2026_07" into a running month number, or null if it isn't exactly that.
function webcare_feed_month_index( $value ) {
    if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{4}_[0-9]{2}\z/', $value ) ) {
        return null;
    }
    $parsed = webcare_parse_month_key( $value );
    if ( null === $parsed ) {
        return null;
    }
    return ( $parsed[0] * 12 ) + ( $parsed[1] - 1 );
}

// "2026_07" for a running month number.
function webcare_feed_month_key_from_index( $index ) {
    return sprintf( '%04d_%02d', intdiv( $index, 12 ), ( $index % 12 ) + 1 );
}

// Checks the from/to months. Returns [ from_index, to_index ] or a 400 error.
// Rules: both exactly YYYY_MM; from not after to; at most 24 months; not more than one month ahead.
function webcare_feed_check_range( $from, $to ) {
    $from_index = webcare_feed_month_index( $from );
    $to_index   = webcare_feed_month_index( $to );
    $bad        = function ( $message ) {
        return new WP_Error( 'webcare_bad_range', $message, [ 'status' => 400 ] );
    };

    if ( null === $from_index || null === $to_index ) {
        return $bad( __( 'Please give "from" and "to" as months written YYYY_MM, for example 2026_07.', 'webcare' ) );
    }
    if ( $from_index > $to_index ) {
        return $bad( __( '"from" must not be after "to".', 'webcare' ) );
    }
    if ( ( $to_index - $from_index + 1 ) > webcare_feed_max_months() ) {
        return $bad( __( 'Please ask for 24 months or fewer.', 'webcare' ) );
    }
    $now_index = webcare_feed_month_index( webcare_stats_month_key() );
    if ( null !== $now_index && $to_index > $now_index + 1 ) {
        return $bad( __( '"to" is too far in the future.', 'webcare' ) );
    }
    return [ $from_index, $to_index ];
}

/* ------------------------------------------------------------------
 * Building the report
 * ---------------------------------------------------------------- */

// A "2026-08-20" start date as stored, or null. Only reads: unlike the admin page it never sets the date.
function webcare_feed_started_date( $option ) {
    $started = get_option( $option, '' );
    return ( is_string( $started ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $started ) ) ? $started : null;
}

// One month's visits in the shape sent out: page addresses as a JSON "object" (so an empty list is
// {} not []), at most 200 of them, and only ones that are plain text.
function webcare_feed_visit_month( $stored ) {
    $stats = webcare_normalise_visit_stats( $stored );
    $pages = [];
    foreach ( $stats['pages'] as $path => $views ) {
        $path = (string) $path;
        if ( '' === $path || webcare_feed_bad_text( $path ) ) {
            continue;
        }
        $pages[ $path ] = (int) $views;
        if ( count( $pages ) >= webcare_visit_pages_cap() ) {
            break;
        }
    }
    $stats['pages'] = (object) $pages;
    return $stats;
}

// True if the text is not valid UTF-8 (which would stop the whole report being turned into JSON).
function webcare_feed_bad_text( $text ) {
    return 1 !== preg_match( '//u', (string) $text );
}

// How many updates are waiting, from the numbers WordPress already keeps. 0 if unknown.
function webcare_feed_update_counts() {
    $out = [ 'core' => 0, 'plugins' => 0, 'themes' => 0 ];
    try {
        $core = get_site_transient( 'update_core' );
        if ( is_object( $core ) && isset( $core->updates ) && is_array( $core->updates ) ) {
            foreach ( $core->updates as $offer ) {
                if ( is_object( $offer ) && isset( $offer->response ) && 'upgrade' === $offer->response ) {
                    $out['core'] = 1; // Either there is a WordPress update or there isn't.
                }
            }
        }
        $plugins = get_site_transient( 'update_plugins' );
        if ( is_object( $plugins ) && isset( $plugins->response ) && is_array( $plugins->response ) ) {
            $out['plugins'] = count( $plugins->response );
        }
        $themes = get_site_transient( 'update_themes' );
        if ( is_object( $themes ) && isset( $themes->response ) && is_array( $themes->response ) ) {
            $out['themes'] = count( $themes->response );
        }
    } catch ( \Throwable $e ) {
        return [ 'core' => 0, 'plugins' => 0, 'themes' => 0 ];
    }
    return $out;
}

function webcare_feed_theme_name() {
    try {
        $name = wp_get_theme()->get( 'Name' );
        return ( is_string( $name ) && ! webcare_feed_bad_text( $name ) ) ? $name : '';
    } catch ( \Throwable $e ) {
        return '';
    }
}

// The same decision the Business details page reports, as a short word:
// 'yoast' | 'webcare' | 'other_seo_plugin' | 'none' (not enough details yet) | 'off' (switched off on this site).
function webcare_feed_business_published() {
    if ( ! webcare_business_schema_enabled() ) {
        return 'off';
    }
    $route = webcare_schema_route();
    if ( 'yoast_local' === $route || 'other_seo' === $route ) {
        return 'other_seo_plugin';
    }
    if ( ! webcare_should_publish_business_schema() ) {
        return 'none';
    }
    return ( 'yoast' === $route ) ? 'yoast' : 'webcare';
}

// The whole report for the running month numbers $from_index to $to_index (already checked).
// Every month in the range is present; a month with no figures shows zeros. Every stored value goes
// through the same cleaning as the Webcare page, so damaged data can't break the JSON.
function webcare_build_report( $from_index, $to_index ) {
    $months = [];
    for ( $i = $from_index; $i <= $to_index; $i++ ) {
        $key            = webcare_feed_month_key_from_index( $i );
        $months[ $key ] = [
            'enquiries' => webcare_normalise_counts( get_option( webcare_stats_option_name( $key ), [] ) ),
            'visits'    => webcare_feed_visit_month( get_option( webcare_visits_option_name( $key ), [] ) ),
        ];
    }

    $wp_version = get_bloginfo( 'version' );
    $active     = get_option( 'active_plugins', [] );

    return [
        'schema'           => 1,
        'plugin_version'   => WEBCARE_VERSION,
        'home_url'         => home_url( '/' ),
        'generated_at'     => gmdate( 'c' ),
        'timezone'         => wp_timezone_string(),
        'range'            => [
            'from' => webcare_feed_month_key_from_index( $from_index ),
            'to'   => webcare_feed_month_key_from_index( $to_index ),
        ],
        'tracking'         => [
            'enquiries_enabled' => webcare_tracking_enabled(),
            'visits_enabled'    => webcare_visits_enabled(),
            'enquiries_started' => webcare_feed_started_date( 'webcare_tracking_started' ),
            'visits_started'    => webcare_feed_started_date( 'webcare_visits_started' ),
        ],
        'months'           => $months,
        'care'             => [
            'wp_version'     => is_string( $wp_version ) ? $wp_version : '',
            'php_version'    => PHP_VERSION,
            'theme'          => webcare_feed_theme_name(),
            'updates'        => webcare_feed_update_counts(),
            'active_plugins' => is_array( $active ) ? count( $active ) : 0,
        ],
        'business_details' => [
            'complete'  => webcare_should_publish_business_schema(),
            'published' => webcare_feed_business_published(),
        ],
    ];
}

// Notes when APM last fetched, for the admin page. Written at most once an hour so a busy caller
// can't cause a database write on every request.
function webcare_note_feed_fetch() {
    $last = get_option( 'webcare_feed_last_fetch', 0 );
    $last = is_numeric( $last ) ? (int) $last : 0;
    if ( ( time() - $last ) > HOUR_IN_SECONDS ) {
        update_option( 'webcare_feed_last_fetch', time(), false );
    }
}

/* ------------------------------------------------------------------
 * The address: GET /wp-json/webcare/v1/report
 * ---------------------------------------------------------------- */

function webcare_register_report_route() {
    try {
        // Switched off: the address simply doesn't exist (the check inside webcare_report_permission
        // covers a switch that is turned on later than this point).
        if ( ! webcare_report_feed_enabled() ) {
            return;
        }
        register_rest_route(
            'webcare/v1',
            '/report',
            [
                'methods'             => 'GET',
                'callback'            => 'webcare_rest_report',
                // "from" and "to" are deliberately NOT declared as arguments here: WordPress would
                // check them (and reply with details of what is wrong) BEFORE the permission check.
                // We want strangers to learn nothing, so they are checked after the signature.
                'permission_callback' => 'webcare_report_permission',
            ]
        );
    } catch ( \Throwable $e ) {
        return;
    }
}

// Only reached once the signature has been accepted.
function webcare_rest_report( $request ) {
    try {
        $range = webcare_feed_check_range( $request->get_param( 'from' ), $request->get_param( 'to' ) );
        if ( is_wp_error( $range ) ) {
            return $range;
        }

        $response = new WP_REST_Response( webcare_build_report( $range[0], $range[1] ), 200 );
        webcare_feed_add_no_cache_headers( $response );

        webcare_note_feed_fetch();
        return $response;
    } catch ( \Throwable $e ) {
        return new WP_Error( 'webcare_feed_error', __( 'The report could not be created.', 'webcare' ), [ 'status' => 500 ] );
    }
}

// Asks caches (and anything in between) never to keep an answer: the modern header plus the two
// old-fashioned ones some caches still look at.
function webcare_feed_add_no_cache_headers( $response ) {
    $response->header( 'Cache-Control', 'no-store' );
    $response->header( 'Pragma', 'no-cache' );
    $response->header( 'Expires', '0' );
}

// Does this to every answer from our address, including the "not authorised" ones. The address is
// matched ignoring capital letters and a trailing slash, so odd spellings of it are covered too.
function webcare_feed_no_store( $response, $server = null, $request = null ) {
    try {
        if ( is_object( $request ) && '/webcare/v1/report' === strtolower( rtrim( (string) $request->get_route(), '/' ) ) && is_object( $response ) && method_exists( $response, 'header' ) ) {
            webcare_feed_add_no_cache_headers( $response );
        }
    } catch ( \Throwable $e ) {
        return $response;
    }
    return $response;
}

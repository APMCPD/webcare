<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Enquiry actions: a privacy-friendly count of how often visitors tap a phone number,
 * an email address or a booking link, and how many contact forms are sent.
 *
 * What is stored: four plain totals per month. No cookies, no names or contact details, no page
 * addresses and no message text. The only thing tied to a visitor's connection is a temporary
 * scrambled code (a one-way hash) used for the spam limit, which is discarded after 10 minutes.
 *
 * This runs on the public side of every client site, so everything is defensive: the
 * front-end and REST code is wrapped in try/catch and, if anything goes wrong, it
 * simply does nothing.
 */

/* ------------------------------------------------------------------
 * Fixed lists and on/off switch
 * ---------------------------------------------------------------- */

// The only event types ever accepted or stored.
function webcare_event_types() {
    return [ 'phone', 'email', 'booking', 'form' ];
}

// Sites that count as "online booking" links. A visitor's click on a link to any of these
// (or their sub-sites, e.g. myclinic.cliniko.com) is counted as a booking click.
function webcare_default_booking_hosts() {
    return [
        'cliniko.com',
        'janeapp.com',
        'as.me',
        'acuityscheduling.com',
        'calendly.com',
        'setmore.com',
        'simplybook.me',
        'fresha.com',
    ];
}

// Can be switched off on one site:
//   add_filter( 'webcare_track_enquiries', '__return_false' );
function webcare_tracking_enabled() {
    return (bool) apply_filters( 'webcare_track_enquiries', true );
}

// A web address's host with any "www." removed, in lower case. '' if there isn't one.
function webcare_plain_host( $url ) {
    $host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
    if ( ! is_string( $host ) || '' === $host ) {
        return '';
    }
    $host = strtolower( $host );
    if ( 0 === strpos( $host, 'www.' ) ) {
        $host = substr( $host, 4 );
    }
    return $host;
}

// Turns the client's own "Online booking link" into a rule the script understands:
// "example.com" (the whole site) or "example.co.uk/book" (just that folder).
// Returns '' if it isn't usable. A link to the site's own home page is refused, because
// it would count every ordinary click on the menu as a booking.
function webcare_booking_rule_from_url( $url ) {
    if ( ! is_string( $url ) || '' === trim( $url ) ) {
        return '';
    }
    $parts = wp_parse_url( trim( $url ) );
    if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
        return '';
    }
    if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
        return '';
    }
    $host = webcare_plain_host( trim( $url ) );
    $path = isset( $parts['path'] ) ? rtrim( strtolower( $parts['path'] ), '/' ) : '';
    if ( '' === $host ) {
        return '';
    }
    if ( '' === $path && $host === webcare_plain_host( home_url( '/' ) ) ) {
        return '';
    }
    return substr( $host . $path, 0, 200 );
}

// Everything the browser script needs to recognise a booking link.
function webcare_booking_rules() {
    $rules = webcare_default_booking_hosts();

    if ( function_exists( 'webcare_get_business_details' ) ) {
        $details = webcare_get_business_details();
        $custom  = webcare_booking_rule_from_url( isset( $details['booking_url'] ) ? $details['booking_url'] : '' );
        if ( '' !== $custom && ! in_array( $custom, $rules, true ) ) {
            $rules[] = $custom;
        }
    }
    return $rules;
}

/* ------------------------------------------------------------------
 * Months and quarters
 * ---------------------------------------------------------------- */

// "2026_10" for the month containing the timestamp (default: now), in the site's own timezone.
function webcare_stats_month_key( $timestamp = null ) {
    if ( null === $timestamp ) {
        $timestamp = time();
    }
    $key = function_exists( 'wp_date' ) ? wp_date( 'Y_m', (int) $timestamp ) : false;
    if ( ! is_string( $key ) || ! preg_match( '/^\d{4}_\d{2}$/', $key ) ) {
        $key = gmdate( 'Y_m', (int) $timestamp );
    }
    return $key;
}

// One option per month, e.g. webcare_stats_2026_10.
function webcare_stats_option_name( $month_key ) {
    return 'webcare_stats_' . $month_key;
}

// Splits "2026_10" into [ 2026, 10 ], or null if it isn't a valid month.
function webcare_parse_month_key( $key ) {
    if ( ! is_string( $key ) || ! preg_match( '/^(\d{4})_(\d{2})$/', $key, $m ) ) {
        return null;
    }
    $year  = (int) $m[1];
    $month = (int) $m[2];
    if ( $month < 1 || $month > 12 ) {
        return null;
    }
    return [ $year, $month ];
}

// The calendar quarter (Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec) this many quarters from now.
// 0 = this quarter, -1 = last quarter. Returns first and last month as "2026_07" keys.
function webcare_quarter_info( $quarter_offset = 0, $timestamp = null ) {
    $now = webcare_parse_month_key( webcare_stats_month_key( $timestamp ) );
    if ( null === $now ) {
        $now = [ (int) gmdate( 'Y' ), (int) gmdate( 'n' ) ];
    }
    $index   = ( $now[0] * 4 ) + intdiv( $now[1] - 1, 3 ) + (int) $quarter_offset;
    $year    = intdiv( $index, 4 );
    $quarter = $index % 4; // 0 to 3
    $first   = ( $quarter * 3 ) + 1;
    $last    = $first + 2;

    return [
        'year'       => $year,
        'quarter'    => $quarter + 1,
        'first'      => $first,
        'last'       => $last,
        'from_month' => sprintf( '%04d_%02d', $year, $first ),
        'to_month'   => sprintf( '%04d_%02d', $year, $last ),
    ];
}

/* ------------------------------------------------------------------
 * Storing and reading the totals
 * ---------------------------------------------------------------- */

// Any stored value turned into a safe set of four whole numbers.
function webcare_normalise_counts( $value ) {
    $out = [];
    foreach ( webcare_event_types() as $type ) {
        $n           = ( is_array( $value ) && isset( $value[ $type ] ) ) ? (int) $value[ $type ] : 0;
        $out[ $type ] = ( $n > 0 ) ? $n : 0;
    }
    return $out;
}

// Remembers the day counting began (once), so the admin page can say "counted since ...".
// Kept as a tiny autoloaded option so checking it on page views costs nothing extra.
function webcare_ensure_tracking_started() {
    if ( false === get_option( 'webcare_tracking_started', false ) ) {
        $today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        add_option( 'webcare_tracking_started', is_string( $today ) ? $today : gmdate( 'Y-m-d' ), '', 'yes' );
    }
}

// Adds one to this month's total for the given type. Returns true if it was counted.
//
// Trade-off (accepted on purpose): this reads the month's option, adds one and writes it
// back. If two visitors click at exactly the same instant one count can be lost. For the
// low-traffic clinic sites we host that is negligible, and it avoids a custom database table.
// The option is not autoloaded, so it never slows down ordinary page loads.
function webcare_record_event( $type ) {
    if ( ! is_string( $type ) || ! in_array( $type, webcare_event_types(), true ) ) {
        return false;
    }
    webcare_ensure_tracking_started();

    $option = webcare_stats_option_name( webcare_stats_month_key() );
    $counts = webcare_normalise_counts( get_option( $option, [] ) );
    $counts[ $type ]++;
    update_option( $option, $counts, false );
    return true;
}

// Totals for every month from $from_month to $to_month inclusive (both like "2026_07").
function webcare_get_enquiry_counts( $from_month, $to_month ) {
    $total = webcare_normalise_counts( [] );
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
        $counts = webcare_normalise_counts( get_option( webcare_stats_option_name( sprintf( '%04d_%02d', $year, $month ) ), [] ) );
        foreach ( $counts as $type => $n ) {
            $total[ $type ] += $n;
        }
        $month++;
        if ( $month > 12 ) {
            $month = 1;
            $year++;
        }
    }
    return $total;
}

// Totals for the current calendar quarter (0) or an earlier/later one (-1 = last quarter).
// (The optional timestamp is only there so the quarter can be worked out for a fixed date when testing.)
function webcare_get_quarter_counts( $quarter_offset = 0, $timestamp = null ) {
    $info = webcare_quarter_info( $quarter_offset, $timestamp );
    return webcare_get_enquiry_counts( $info['from_month'], $info['to_month'] );
}

/* ------------------------------------------------------------------
 * Which visitors are counted
 * ---------------------------------------------------------------- */

// Staff who can use Webcare (Editors and Administrators) are never counted, so testing the
// site doesn't inflate the numbers.
function webcare_is_staff_visitor() {
    return function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( WEBCARE_CAPABILITY );
}

// Obvious robots: no browser name at all, or a name that says it is a robot / crawler /
// headless browser / monitoring tool.
function webcare_is_bot_user_agent( $user_agent ) {
    if ( ! is_string( $user_agent ) ) {
        return true;
    }
    $user_agent = trim( substr( $user_agent, 0, 500 ) );
    if ( '' === $user_agent ) {
        return true;
    }
    // "bot" must stand alone or be followed by "/" or "-" (Googlebot/2.1, Slackbot-...), so that
    // phone brands such as "Cubot" are not mistaken for robots. A web address inside the browser
    // name ("+http://...") is how most crawlers introduce themselves; real browsers never have one.
    return 1 === preg_match( '/\bbot\b|bot[\/\-]|crawl|spider|slurp|headless|lighthouse|pingdom|uptimerobot|curl|wget|python-requests|httpclient|scrapy|libwww|https?:\/\//i', $user_agent );
}

function webcare_current_user_agent() {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only ever matched against a pattern, never stored or printed.
    return isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
}

// If the request says where it came from (Origin or Referer header), that must be this
// website (with or without "www."). If it says nothing we let it through; the rate limit
// below still applies. Returns true when the request looks like it came from our own pages.
function webcare_request_is_same_site( $origin, $referer ) {
    $source = '';
    if ( is_string( $origin ) && '' !== trim( $origin ) ) {
        $source = trim( $origin );
    } elseif ( is_string( $referer ) && '' !== trim( $referer ) ) {
        $source = trim( $referer );
    }
    if ( '' === $source ) {
        return true;
    }

    $source_host = webcare_plain_host( $source );
    if ( '' === $source_host ) {
        return false; // e.g. "Origin: null"
    }
    $allowed = [ webcare_plain_host( home_url( '/' ) ), webcare_plain_host( site_url( '/' ) ) ];
    return in_array( $source_host, $allowed, true );
}

// A scrambled (one-way) code for the visitor's connection, used only for the spam limits below.
// The real address is never stored. Forwarding headers are only trusted when the connection
// itself comes from a private/internal address (i.e. our own proxy, such as Varnish), and then
// only the entry that OUR proxy added: for X-Forwarded-For that is the right-most public address
// (anything to its left was typed by the visitor and could be made up).
// CF-Connecting-IP is deliberately not used: it can only be trusted from Cloudflare's own servers.
function webcare_visitor_hash() {
    // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- validated with filter_var below.
    $public_flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    $ip           = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '';

    if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $public_flags ) ) {
        $found = '';

        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $entries = array_reverse( explode( ',', substr( $_SERVER['HTTP_X_FORWARDED_FOR'], 0, 500 ) ) );
            foreach ( $entries as $entry ) {
                $entry = trim( $entry );
                if ( false !== filter_var( $entry, FILTER_VALIDATE_IP, $public_flags ) ) {
                    $found = $entry;
                    break;
                }
            }
        }
        if ( '' === $found && ! empty( $_SERVER['HTTP_X_REAL_IP'] ) && is_string( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $candidate = trim( $_SERVER['HTTP_X_REAL_IP'] );
            if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                $found = $candidate;
            }
        }
        if ( '' !== $found ) {
            $ip = $found;
        }
    }
    // phpcs:enable

    return substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
}

// Counts one hit in a fixed time window kept in a transient. Returns true if the limit was
// already reached (the hit is then not added). The transient expires by itself.
function webcare_window_limit_reached( $key, $max, $window ) {
    $now   = time();
    $state = get_transient( $key );
    if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || ( $now - (int) $state['t'] ) >= $window ) {
        set_transient( $key, [ 'n' => 1, 't' => $now ], $window );
        return false;
    }
    if ( (int) $state['n'] >= $max ) {
        return true;
    }
    set_transient( $key, [ 'n' => (int) $state['n'] + 1, 't' => (int) $state['t'] ], max( 1, $window - ( $now - (int) $state['t'] ) ) );
    return false;
}

// Site-wide brake: at most 300 counted events per hour across ALL visitors. Even if someone
// fakes their address to dodge the per-visitor limit, the totals and the number of temporary
// database rows stay bounded. Returns true if the site is over its limit (and adds a hit if not).
function webcare_over_site_limit() {
    return webcare_window_limit_reached( 'webcare_rl_site', 300, HOUR_IN_SECONDS );
}

// Read-only look at the site-wide brake: true if this hour's 300 are already used up. Checked
// before anything else is written, so a flood creates no new temporary rows once the cap is hit.
function webcare_site_limit_full() {
    $state = get_transient( 'webcare_rl_site' );
    return is_array( $state ) && isset( $state['n'], $state['t'] )
        && ( time() - (int) $state['t'] ) < HOUR_IN_SECONDS
        && (int) $state['n'] >= 300;
}

// Per-visitor brake: at most 30 counted events per visitor per 10 minutes. Returns true if this
// visitor is over the limit (and should not be counted).
function webcare_over_rate_limit( $visitor_hash ) {
    return webcare_window_limit_reached( 'webcare_rl_' . $visitor_hash, 30, 10 * MINUTE_IN_SECONDS );
}

/* ------------------------------------------------------------------
 * The browser script (clicks on phone, email and booking links)
 * ---------------------------------------------------------------- */

// Loads the small script on public pages only.
function webcare_enqueue_tracking() {
    try {
        if ( is_admin() || is_feed() || is_customize_preview() || is_preview() ) {
            return;
        }
        if ( ! webcare_tracking_enabled() ) {
            return;
        }
        // Divi's visual builder and its preview windows.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check, nothing is stored.
        if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) {
            return;
        }
        if ( webcare_is_staff_visitor() ) {
            return;
        }

        webcare_ensure_tracking_started();

        wp_enqueue_script( 'webcare-track', WEBCARE_URL . 'assets/webcare-track.js', [], WEBCARE_VERSION, true );
        if ( function_exists( 'wp_script_add_data' ) ) {
            wp_script_add_data( 'webcare-track', 'strategy', 'defer' ); // Ignored by older WordPress.
        }
        wp_add_inline_script(
            'webcare-track',
            'window.webcareTrack = ' . wp_json_encode(
                [
                    'endpoint' => esc_url_raw( rest_url( 'webcare/v1/event' ) ),
                    'booking'  => webcare_booking_rules(),
                ]
            ) . ';',
            'before'
        );
    } catch ( \Throwable $e ) {
        // Counting is optional - never let it break a page.
        return;
    }
}

/* ------------------------------------------------------------------
 * The address the script reports to: POST /wp-json/webcare/v1/event
 * ---------------------------------------------------------------- */

function webcare_register_event_route() {
    try {
        register_rest_route(
            'webcare/v1',
            '/event',
            [
                'methods'             => 'POST',
                'callback'            => 'webcare_rest_event',
                'args'                => [
                    'type' => [
                        'type'     => 'string',
                        'required' => true,
                        'enum'     => webcare_event_types(),
                    ],
                ],
                // Visitors are anonymous, so there is nobody to check. We deliberately do NOT use
                // a nonce either: pages are cached (Breeze, Varnish), so a nonce printed into a
                // page would be stale by the time a visitor clicks. Instead the request is
                // limited to our own site, to real browsers, to four fixed event types, to
                // 30 per visitor per 10 minutes and to 300 per hour for the whole site. The worst a fake request can do is add to
                // a click total.
                'permission_callback' => '__return_true',
            ]
        );
    } catch ( \Throwable $e ) {
        return;
    }
}

// Quietly answers "204 No Content" for anything we choose not to count, so nobody learns
// which rule stopped them. Only a made-up event type gets an error.
function webcare_rest_event( $request ) {
    try {
        $nothing = new WP_REST_Response( null, 204 );
        $nothing->header( 'Cache-Control', 'no-store' );

        $type = $request->get_param( 'type' );
        if ( ! is_string( $type ) || ! in_array( $type, webcare_event_types(), true ) ) {
            return new WP_Error( 'webcare_bad_type', 'Unknown event type.', [ 'status' => 400 ] );
        }

        if ( ! webcare_tracking_enabled() ) {
            return $nothing;
        }
        if ( webcare_is_bot_user_agent( $request->get_header( 'user_agent' ) ) ) {
            return $nothing;
        }
        if ( ! webcare_request_is_same_site( $request->get_header( 'origin' ), $request->get_header( 'referer' ) ) ) {
            return $nothing;
        }
        // Order matters. A read-only look at the site cap comes first, so once it's full no new
        // rows are written. Then the per-visitor limit, so one visitor's rejected repeats never
        // use up the site's allowance and lock out genuine clicks. Only then is a site slot taken.
        if ( webcare_site_limit_full() ) {
            return $nothing;
        }
        if ( webcare_over_rate_limit( webcare_visitor_hash() ) ) {
            return $nothing;
        }
        if ( webcare_over_site_limit() ) {
            return $nothing;
        }

        webcare_record_event( $type );
        return $nothing;
    } catch ( \Throwable $e ) {
        return new WP_REST_Response( null, 204 );
    }
}

/* ------------------------------------------------------------------
 * Contact form sends (Divi)
 * ---------------------------------------------------------------- */

// Divi runs this action on the server when it has processed a submitted contact form, whether
// the form was sent by Ajax or as a normal page post. So it works on cached pages too.
// Divi passes ( $processed_fields_values, $et_contact_error, $contact_form_info ). We only
// look at the second one and count the send only when there was no error. If Divi ever
// changes what it passes, we count nothing rather than guess.
function webcare_count_divi_form() {
    static $counted = false;

    try {
        $args = func_get_args();
        if ( $counted || count( $args ) < 2 || ! empty( $args[1] ) ) {
            return;
        }
        if ( ! webcare_tracking_enabled() || webcare_is_staff_visitor() ) {
            return;
        }
        if ( webcare_is_bot_user_agent( webcare_current_user_agent() ) ) {
            return;
        }
        $counted = true; // One form send counts once, even if the action were to run twice.
        webcare_record_event( 'form' );
    } catch ( \Throwable $e ) {
        return;
    }
}

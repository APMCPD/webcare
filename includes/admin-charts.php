<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Charts and summaries for the Webcare Connect page (added in 1.6.0).
 *
 * Everything here only READS the monthly totals the plugin already keeps; nothing new is
 * recorded. The charts are plain HTML and CSS (no chart library and nothing loaded from
 * another website), because this plugin runs on every client's site and promises no outside
 * calls. Every chart shows its numbers as text too, and has a written summary for screen readers.
 */

/* ------------------------------------------------------------------
 * Small helpers (pure: no output)
 * ---------------------------------------------------------------- */

// The last $count month keys, oldest first, ending with the current month: [ "2026_06", ... "2026_11" ].
function webcare_recent_month_keys( $count = 6, $timestamp = null ) {
    $now = webcare_parse_month_key( webcare_stats_month_key( $timestamp ) );
    if ( null === $now ) {
        $now = [ (int) gmdate( 'Y' ), (int) gmdate( 'n' ) ];
    }
    $index = ( $now[0] * 12 ) + ( $now[1] - 1 );
    $keys  = [];
    for ( $i = (int) $count - 1; $i >= 0; $i-- ) {
        $n      = $index - $i;
        $keys[] = sprintf( '%04d_%02d', intdiv( $n, 12 ), ( $n % 12 ) + 1 );
    }
    return $keys;
}

// "Nov" for "2026_11".
function webcare_month_short_name( $month_key ) {
    $parts = webcare_parse_month_key( $month_key );
    if ( null === $parts ) {
        return '';
    }
    $stamp = gmmktime( 12, 0, 0, $parts[1], 15, $parts[0] );
    $text  = function_exists( 'wp_date' ) ? wp_date( 'M', $stamp, new DateTimeZone( 'UTC' ) ) : false;
    return is_string( $text ) ? $text : gmdate( 'M', $stamp );
}

// The total of the four enquiry actions.
function webcare_enquiry_total( $counts ) {
    return is_array( $counts ) ? (int) array_sum( array_map( 'intval', $counts ) ) : 0;
}

// Enquiries per 100 visits, to one decimal place, or null when there were no visits.
function webcare_enquiry_rate( $enquiries, $visits ) {
    return ( $visits > 0 ) ? round( ( $enquiries / $visits ) * 100, 1 ) : null;
}

// The month a counter started, as "2026_08" ('' if not known).
function webcare_started_month_key( $date ) {
    return ( is_string( $date ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) ? str_replace( '-', '_', substr( $date, 0, 7 ) ) : '';
}

// One row per recent month: [ key, label, visits, enquiries, current, before_start ]. A month before
// a counter started is flagged so it can be shown as "not counted yet" rather than as zero.
function webcare_monthly_series( $count = 6, $timestamp = null ) {
    $visits_from = webcare_started_month_key( webcare_visits_started_date() );
    $clicks_from = webcare_started_month_key( webcare_tracking_started_date() );
    $keys        = webcare_recent_month_keys( $count, $timestamp );
    $current     = end( $keys );
    $rows        = [];
    foreach ( $keys as $key ) {
        $v      = webcare_get_visit_stats( $key, $key );
        $c      = webcare_get_enquiry_counts( $key, $key );
        $rows[] = [
            'key'             => $key,
            'label'           => webcare_month_short_name( $key ),
            'visits'          => (int) $v['visits'],
            'enquiries'       => webcare_enquiry_total( $c ),
            'current'         => ( $key === $current ),
            // With no start date yet, nothing has been counted: an empty month shows as a dash, not 0.
            'visits_before'   => ( '' === $visits_from ) ? ( (int) $v['visits'] < 1 ) : ( $key < $visits_from ),
            'enquiry_before'  => ( '' === $clicks_from ) ? ( webcare_enquiry_total( $c ) < 1 ) : ( $key < $clicks_from ),
        ];
    }
    return $rows;
}

// A bar's height as a whole percentage of the tallest (at least 2% so a small value still shows).
function webcare_bar_percent( $value, $max ) {
    if ( $max <= 0 || $value <= 0 ) {
        return 0;
    }
    return max( 2, (int) round( ( $value / $max ) * 100 ) );
}

/* ------------------------------------------------------------------
 * Summary tiles
 * ---------------------------------------------------------------- */

// Four headline numbers for this quarter so far. The quarter isn't finished, so last quarter's
// figure is shown beside it for context (labelled when only partly counted) rather than a
// percentage change that would mislead.
function webcare_render_summary_tiles( $visits_on, $clicks_on ) {
    $this_v = webcare_get_quarter_visits( 0 );
    $last_v = webcare_get_quarter_visits( -1 );
    $this_c = webcare_enquiry_total( webcare_get_quarter_counts( 0 ) );
    $last_c = webcare_enquiry_total( webcare_get_quarter_counts( -1 ) );

    $visits_started = webcare_visits_started_date();
    $clicks_started = webcare_tracking_started_date();
    $last_v_cover   = webcare_quarter_coverage( -1, $visits_started, $last_v['visits'] );
    $last_c_cover   = webcare_quarter_coverage( -1, $clicks_started, $last_c );

    $tiles = [];
    if ( $visits_on ) {
        $tiles[] = [ __( 'Visits', 'webcare' ), number_format_i18n( $this_v['visits'] ), webcare_last_quarter_note( $last_v_cover, $last_v['visits'] ) ];
    }
    if ( $clicks_on ) {
        $tiles[] = [ __( 'Enquiries', 'webcare' ), number_format_i18n( $this_c ), webcare_last_quarter_note( $last_c_cover, $last_c ) ];
    }
    if ( $visits_on && $clicks_on ) {
        // A ratio is only fair when visits and enquiries were both counted from the start of the
        // quarter (visits started later than enquiries on most sites); otherwise it shows a dash.
        $this_fair = ( 'full' === webcare_quarter_coverage( 0, $visits_started, $this_v['visits'] ) ) && ( 'full' === webcare_quarter_coverage( 0, $clicks_started, $this_c ) );
        $last_fair = ( 'full' === $last_v_cover ) && ( 'full' === $last_c_cover );
        $rate      = $this_fair ? webcare_enquiry_rate( $this_c, $this_v['visits'] ) : null;
        $last_rate = $last_fair ? webcare_enquiry_rate( $last_c, $last_v['visits'] ) : null;
        if ( ! $this_fair ) {
            $note = __( 'Shown once visits and enquiries have both been counted for a whole quarter', 'webcare' );
        } elseif ( null === $last_rate ) {
            $note = __( 'Last quarter: not available', 'webcare' );
        } else {
            $note = sprintf( /* translators: %s: number */ __( 'Last quarter: %s', 'webcare' ), number_format_i18n( $last_rate, 1 ) );
        }
        $tiles[] = [ __( 'Enquiries per 100 visits', 'webcare' ), ( null === $rate ) ? '' : number_format_i18n( $rate, 1 ), $note ];
    }
    if ( $visits_on ) {
        $device_total = array_sum( $this_v['devices'] );
        $tiles[]      = [
            __( 'On a phone', 'webcare' ),
            ( $device_total > 0 ) ? webcare_percent( $this_v['devices']['mobile'], $device_total ) . '%' : '',
            __( 'of visits this quarter', 'webcare' ),
        ];
    }
    if ( empty( $tiles ) ) {
        return;
    }

    echo '<p class="webcare-help">' . esc_html__( 'This quarter so far.', 'webcare' ) . '</p>';
    echo '<div class="webcare-tiles">';
    foreach ( $tiles as $tile ) {
        // An empty value means "nothing to show yet" and is drawn as a dash.
        $value = ( '' === $tile[1] ) ? '&mdash;' : esc_html( $tile[1] );
        echo '<div class="webcare-tile"><div class="webcare-tile-label">' . esc_html( $tile[0] ) . '</div>'
            . '<div class="webcare-tile-value">' . $value . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped (or a fixed entity) just above.
            . '<div class="webcare-tile-note">' . esc_html( $tile[2] ) . '</div></div>';
    }
    echo '</div>';
}

// How much of a quarter was counted, given the day counting started ("2026-08-20" or ''):
// 'full' (from its first month), 'partial' (started part-way through) or 'none'. With no start
// date known, any figures at all are taken as a full count, as the older comparison table did.
function webcare_quarter_coverage( $quarter_offset, $started_date, $value = 0 ) {
    $from = webcare_started_month_key( $started_date );
    if ( '' === $from ) {
        return ( $value > 0 ) ? 'full' : 'none';
    }
    $info = webcare_quarter_info( $quarter_offset );
    if ( $from <= $info['from_month'] ) {
        return 'full';
    }
    return ( $from <= $info['to_month'] ) ? 'partial' : 'none';
}

// "Last quarter: 1,187", "Last quarter (partly counted): 312" or "Last quarter: not counted".
function webcare_last_quarter_note( $coverage, $value ) {
    if ( 'full' === $coverage ) {
        return sprintf( /* translators: %s: number */ __( 'Last quarter: %s', 'webcare' ), number_format_i18n( $value ) );
    }
    if ( 'partial' === $coverage ) {
        return sprintf( /* translators: %s: number */ __( 'Last quarter (partly counted): %s', 'webcare' ), number_format_i18n( $value ) );
    }
    return __( 'Last quarter: not counted', 'webcare' );
}

/* ------------------------------------------------------------------
 * Six-month chart
 * ---------------------------------------------------------------- */

// Two small bar charts (visits, then enquiries), each on its own scale: they differ by far too
// much to share one. The current month is drawn lighter because it isn't finished.
function webcare_render_trend_chart( $visits_on, $clicks_on ) {
    $rows = webcare_monthly_series( 6 );

    echo '<h3>' . esc_html__( 'Last six months', 'webcare' ) . '</h3>';
    if ( $visits_on ) {
        webcare_render_month_bars( $rows, 'visits', 'visits_before', __( 'Visits', 'webcare' ), 'webcare-bar-visits' );
    }
    if ( $clicks_on ) {
        webcare_render_month_bars( $rows, 'enquiries', 'enquiry_before', __( 'Enquiries', 'webcare' ), 'webcare-bar-enquiries' );
    }
    echo '<p class="webcare-help">' . esc_html__( 'The lighter bar is this month so far. Months before counting started are marked with a dash.', 'webcare' ) . '</p>';
}

function webcare_render_month_bars( $rows, $field, $before_field, $title, $class ) {
    $max     = 0;
    $summary = [];
    foreach ( $rows as $row ) {
        if ( ! $row[ $before_field ] ) {
            $max       = max( $max, $row[ $field ] );
            $summary[] = $row['label'] . ' ' . number_format_i18n( $row[ $field ] ) . ( $row['current'] ? ' ' . __( '(so far)', 'webcare' ) : '' );
        }
    }
    $label = $title . ': ' . ( empty( $summary ) ? __( 'not counted yet', 'webcare' ) : implode( ', ', $summary ) );

    echo '<div class="webcare-chart">';
    echo '<div class="webcare-chart-title">' . esc_html( $title ) . '</div>';
    echo '<div class="webcare-columns" role="img" aria-label="' . esc_attr( $label ) . '">';
    foreach ( $rows as $row ) {
        $before = $row[ $before_field ];
        $value  = $row[ $field ];
        $height = $before ? 0 : webcare_bar_percent( $value, $max );
        echo '<div class="webcare-column' . ( $row['current'] ? ' is-current' : '' ) . '">';
        echo '<span class="webcare-column-value" aria-hidden="true">' . ( $before ? '&ndash;' : esc_html( number_format_i18n( $value ) ) ) . '</span>';
        echo '<span class="webcare-column-track" aria-hidden="true"><span class="webcare-column-bar ' . esc_attr( $class ) . '" style="height:' . esc_attr( (string) $height ) . '%"></span></span>';
        echo '<span class="webcare-column-label" aria-hidden="true">' . esc_html( $row['label'] ) . '</span>';
        echo '</div>';
    }
    echo '</div></div>';
}

/* ------------------------------------------------------------------
 * Where visits came from (one stacked bar + legend)
 * ---------------------------------------------------------------- */

function webcare_render_sources_chart( $this_v, $last_v ) {
    $labels = [
        'search' => __( 'Search engines', 'webcare' ),
        'direct' => __( 'Typed in or bookmarked', 'webcare' ),
        'social' => __( 'Social media', 'webcare' ),
        'ai'     => __( 'AI assistants', 'webcare' ),
        'other'  => __( 'Other websites', 'webcare' ),
    ];
    $total      = array_sum( $this_v['sources'] );
    $last_total = array_sum( $last_v['sources'] );

    echo '<h4>' . esc_html__( 'Where visits came from', 'webcare' ) . '</h4>';
    if ( $total < 1 ) {
        echo '<p class="webcare-help">' . esc_html__( 'No visits counted yet this quarter.', 'webcare' ) . '</p>';
        return;
    }

    $summary = [];
    foreach ( $labels as $key => $label ) {
        $summary[] = $label . ' ' . webcare_percent( $this_v['sources'][ $key ], $total ) . '%';
    }
    echo '<div class="webcare-stack" role="img" aria-label="' . esc_attr( implode( ', ', $summary ) ) . '">';
    foreach ( $labels as $key => $label ) {
        $n = (int) $this_v['sources'][ $key ];
        if ( $n > 0 ) {
            // Width as an exact share so the segments add up to the full bar.
            $width = round( ( $n / $total ) * 100, 2 );
            echo '<span class="webcare-stack-part webcare-src-' . esc_attr( $key ) . '" style="width:' . esc_attr( (string) $width ) . '%"></span>';
        }
    }
    echo '</div>';

    echo '<ul class="webcare-legend">';
    foreach ( $labels as $key => $label ) {
        $n    = (int) $this_v['sources'][ $key ];
        $text = sprintf( '%s: %s (%s%%)', $label, number_format_i18n( $n ), webcare_percent( $n, $total ) );
        $note = '';
        // AI assistants are called out with last quarter's share: they tie in with APM's Reach check.
        if ( 'ai' === $key && $last_total > 0 ) {
            $note = sprintf( /* translators: %s: percentage */ __( 'last quarter %s%%', 'webcare' ), webcare_percent( (int) $last_v['sources']['ai'], $last_total ) );
        }
        echo '<li><span class="webcare-swatch webcare-src-' . esc_attr( $key ) . '" aria-hidden="true"></span>' . esc_html( $text )
            . ( '' !== $note ? ' <span class="webcare-legend-note">&middot; ' . esc_html( $note ) . '</span>' : '' ) . '</li>';
    }
    echo '</ul>';
}

/* ------------------------------------------------------------------
 * How people got in touch (horizontal bars)
 * ---------------------------------------------------------------- */

function webcare_render_enquiry_bars( $counts ) {
    $rows = [
        'phone'   => __( 'Phone number clicks', 'webcare' ),
        'booking' => __( 'Online booking link clicks', 'webcare' ),
        'form'    => __( 'Contact form sends', 'webcare' ),
        'email'   => __( 'Email link clicks', 'webcare' ),
    ];
    $max = 0;
    foreach ( $rows as $type => $label ) {
        $max = max( $max, (int) $counts[ $type ] );
    }

    echo '<ul class="webcare-hbars">';
    foreach ( $rows as $type => $label ) {
        $n = (int) $counts[ $type ];
        echo '<li><span class="webcare-hbar-label">' . esc_html( $label ) . '</span>'
            . '<span class="webcare-hbar-track" aria-hidden="true"><span class="webcare-hbar-fill" style="width:' . esc_attr( (string) webcare_bar_percent( $n, $max ) ) . '%"></span></span>'
            . '<span class="webcare-hbar-value">' . esc_html( number_format_i18n( $n ) ) . '</span></li>';
    }
    echo '</ul>';
}

/* ------------------------------------------------------------------
 * "Your Webcare setup" checklist
 * ---------------------------------------------------------------- */

// Each item: [ done (bool), text, optional link URL, optional link text ]. Only reads saved settings.
function webcare_setup_items( $details = null ) {
    $items = [];

    $visits_on = function_exists( 'webcare_visits_enabled' ) && webcare_visits_enabled();
    $clicks_on = function_exists( 'webcare_tracking_enabled' ) && webcare_tracking_enabled();
    $items[]   = ( $visits_on && $clicks_on )
        ? [ true, __( 'Visit and enquiry counting is on', 'webcare' ) ]
        : [ false, __( 'Visit or enquiry counting is switched off on this website — ask APM if that is not intended', 'webcare' ) ];

    $test = function_exists( 'webcare_email_get_last_test' ) ? webcare_email_get_last_test() : [];
    if ( empty( $test ) ) {
        $items[] = [ false, __( 'Website emails not tested yet', 'webcare' ), admin_url( 'admin.php?page=webcare#webcare-email' ), __( 'Send a test email', 'webcare' ) ];
    } elseif ( $test['ok'] ) {
        $items[] = [ true, sprintf( /* translators: %s: date */ __( 'Website emails working (tested %s)', 'webcare' ), webcare_email_time_text( $test['time'] ) ) ];
    } else {
        $items[] = [ false, __( 'The last test email could not be sent', 'webcare' ), admin_url( 'admin.php?page=webcare#webcare-email' ), __( 'See the email check', 'webcare' ) ];
    }

    $feed_on = function_exists( 'webcare_report_feed_enabled' ) && webcare_report_feed_enabled();
    $last    = get_option( 'webcare_feed_last_fetch', 0 );
    if ( ! $feed_on ) {
        $items[] = [ false, __( 'The connection to APM is switched off on this website — ask APM if that is not intended', 'webcare' ) ];
    } elseif ( is_numeric( $last ) && (int) $last > 0 ) {
        $items[] = [ true, sprintf( /* translators: %s: date */ __( 'Connected to APM (figures last fetched %s)', 'webcare' ), webcare_email_time_text( (int) $last ) ) ];
    } else {
        $items[] = [ false, __( 'APM hasn\'t fetched your figures yet', 'webcare' ), current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=webcare#webcare-connection' ) : '', __( 'See the connection key', 'webcare' ) ];
    }

    if ( ! is_array( $details ) ) {
        $details = function_exists( 'webcare_get_business_details' ) ? webcare_get_business_details() : [];
    }
    $published = function_exists( 'webcare_business_status' ) ? webcare_business_status() : [ 'warning', '' ];
    $biz_url   = admin_url( 'admin.php?page=webcare-business' );
    $items[]   = ( 'success' === $published[0] )
        ? [ true, __( 'Business details published for Google and AI', 'webcare' ) ]
        : [ false, __( 'Business details not published yet', 'webcare' ), $biz_url, __( 'Check your business details', 'webcare' ) ];

    $has_hours = false;
    if ( isset( $details['hours'] ) && is_array( $details['hours'] ) ) {
        foreach ( $details['hours'] as $day ) {
            if ( is_array( $day ) && ( ! empty( $day['closed'] ) || ( isset( $day['open1'] ) && '' !== $day['open1'] ) ) ) {
                $has_hours = true;
                break;
            }
        }
    }
    $items[] = $has_hours
        ? [ true, __( 'Opening hours added', 'webcare' ) ]
        : [ false, __( 'Opening hours not added', 'webcare' ), $biz_url, __( 'Add opening hours', 'webcare' ) ];

    return $items;
}

function webcare_render_setup_checklist() {
    $details = function_exists( 'webcare_get_business_details' ) ? webcare_get_business_details() : [];
    $items   = webcare_setup_items( $details );
    $done  = count( array_filter( $items, function ( $item ) {
        return ! empty( $item[0] );
    } ) );
    $total = count( $items );

    echo '<p class="webcare-progress-text">' . esc_html( sprintf( /* translators: 1: number done, 2: total */ __( '%1$s of %2$s done', 'webcare' ), number_format_i18n( $done ), number_format_i18n( $total ) ) ) . '</p>';
    echo '<div class="webcare-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $total ) . '" aria-valuenow="' . esc_attr( (string) $done ) . '" aria-label="' . esc_attr__( 'Webcare setup', 'webcare' ) . '"><span style="width:' . esc_attr( (string) webcare_bar_percent( $done, $total ) ) . '%"></span></div>';

    echo '<ul class="webcare-checklist">';
    foreach ( $items as $item ) {
        $ok   = ! empty( $item[0] );
        $link = ( ! $ok && ! empty( $item[2] ) ) ? ' &middot; <a href="' . esc_url( $item[2] ) . '">' . esc_html( $item[3] ) . '</a>' : '';
        echo '<li class="' . ( $ok ? 'is-done' : 'is-todo' ) . '"><span class="dashicons ' . ( $ok ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '" aria-hidden="true"></span>'
            . '<span class="screen-reader-text">' . ( $ok ? esc_html__( 'Done:', 'webcare' ) : esc_html__( 'To do:', 'webcare' ) ) . ' </span>'
            . esc_html( $item[1] ) . $link . '</li>';
    }
    echo '</ul>';

    // Optional, so not counted in the progress: not every clinic takes bookings online.
    if ( empty( $details['booking_url'] ) ) {
        echo '<p class="webcare-help">' . esc_html__( 'Tip: if patients can book online, add your booking link in your business details so Google and AI assistants can point people straight to it.', 'webcare' ) . '</p>';
    }
    echo '<p class="webcare-help">' . esc_html__( 'Your quarterly website health check comes from APM; we\'ll show a plain-English summary here once it\'s available.', 'webcare' ) . '</p>';
}

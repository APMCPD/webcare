<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Menu + assets
 * ---------------------------------------------------------------- */

function webcare_register_menu() {
    add_menu_page(
        __( 'Webcare', 'webcare' ),
        __( 'Webcare', 'webcare' ),
        WEBCARE_CAPABILITY,
        'webcare',
        'webcare_render_page',
        'dashicons-heart',
        '2.9' // Just below Dashboard; a decimal string avoids clashing with other menus.
    );
}

// Load the stylesheet only on the Webcare page and the Dashboard.
function webcare_enqueue_assets( $hook ) {
    $business_hook = function_exists( 'webcare_business_hook_suffix' ) ? webcare_business_hook_suffix() : '';
    $is_business   = ( '' !== $business_hook && $business_hook === $hook );
    if ( 'toplevel_page_webcare' !== $hook && 'index.php' !== $hook && ! $is_business ) {
        return;
    }
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        return;
    }
    wp_enqueue_style( 'webcare-admin', WEBCARE_URL . 'assets/webcare-admin.css', [], WEBCARE_VERSION );
}

/* ------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------- */

// Fixed messages for the ?webcare_msg= value. We never print the raw query value.
function webcare_messages() {
    $support = webcare_get_support_email();
    return [
        'sent'         => [ 'success', __( 'Thanks — your request has been sent. We\'ll email you a confirmation shortly.', 'webcare' ) ],
        'invalid'      => [ 'error', __( 'Please check the form — your name, a valid email address and a description of the change are all needed.', 'webcare' ) ],
        'too_long'     => [ 'error', __( 'Sorry, something you typed was too long. Please shorten it and try again.', 'webcare' ) ],
        'rate_limited' => [ 'error', __( 'Please wait a minute before sending another request.', 'webcare' ) ],
        'business_saved'       => [ 'success', __( 'Thanks — your business details have been saved. Changes can take a few minutes to appear because of page caching.', 'webcare' ) ],
        'business_saved_hours' => [ 'warning', __( 'Your business details have been saved, but some opening times were not valid (or closing was not after opening) and have been left out. Please check the opening hours below.', 'webcare' ) ],
        'business_saved_blank_days' => [ 'warning', __( 'Saved — note: days left blank will be shown to Google as closed.', 'webcare' ) ],
        'business_saved_booking' => [ 'warning', __( 'Your business details have been saved, but the online booking link could not be used and was left out. It needs to be the web address of a booking page, for example https://yourclinic.cliniko.com or yourwebsite.co.uk/book.', 'webcare' ) ],
        'business_invalid'     => [ 'error', __( 'Nothing was saved. Please check the business type and email address, then try again.', 'webcare' ) ],
        'key_renewed'  => [ 'success', __( 'A new connection key has been created. The old key has stopped working, so please give the new key to APM.', 'webcare' ) ],
        'not_setup'    => [ 'error', __( 'Online requests aren\'t set up yet — please contact us directly.', 'webcare' ) ],
        'send_failed'  => [
            'error',
            $support
                ? sprintf(
                    /* translators: %s: support email address */
                    __( 'Sorry, your request couldn\'t be sent. Please email us directly at %s.', 'webcare' ),
                    $support
                )
                : __( 'Sorry, your request couldn\'t be sent. Please contact us directly.', 'webcare' ),
        ],
    ];
}

function webcare_render_notice() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only key, mapped to fixed text.
    if ( empty( $_GET['webcare_msg'] ) ) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $key      = sanitize_key( wp_unslash( $_GET['webcare_msg'] ) );
    $messages = webcare_messages();
    if ( ! isset( $messages[ $key ] ) ) {
        return;
    }
    printf(
        '<div class="notice notice-%1$s is-dismissible" role="alert"><p>%2$s</p></div>',
        esc_attr( $messages[ $key ][0] ),
        esc_html( $messages[ $key ][1] )
    );
}

function webcare_render_page() {
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        wp_die( esc_html__( 'Unauthorised', 'webcare' ), 403 );
    }
    ?>
    <div class="wrap webcare-wrap">
        <h1><?php echo esc_html__( 'Webcare', 'webcare' ); ?></h1>
        <?php webcare_render_notice(); ?>

        <div class="webcare-card">
            <h2><?php echo esc_html__( 'Website health', 'webcare' ); ?></h2>
            <?php webcare_render_health_section(); ?>
        </div>

        <div class="webcare-card" id="webcare-enquiries">
            <h2><?php echo esc_html__( 'Your website this quarter', 'webcare' ); ?></h2>
            <?php webcare_render_activity_section(); ?>
        </div>

        <div class="webcare-card">
            <h2><?php echo esc_html__( 'Business details for Google & AI', 'webcare' ); ?></h2>
            <p><?php echo esc_html__( 'Help Google & AI find you — check your business details.', 'webcare' ); ?></p>
            <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=webcare-business' ) ); ?>"><?php echo esc_html__( 'Check your business details', 'webcare' ); ?></a></p>
        </div>

        <div class="webcare-card" id="webcare-request">
            <h2><?php echo esc_html__( 'Request a change', 'webcare' ); ?></h2>
            <?php webcare_render_request_form(); ?>
        </div>

        <div class="webcare-card">
            <h2><?php echo esc_html__( 'Your service', 'webcare' ); ?></h2>
            <?php webcare_render_service_section(); ?>
        </div>

        <?php webcare_render_connection_card(); ?>
    </div>
    <?php
}

// Kept as its own function so a later version can swap in real health checks.
function webcare_render_health_section() {
    echo '<p>' . esc_html__( 'Your first quarterly website health check is coming soon. Every quarter we check your site\'s speed, security and search-engine setup, and we\'ll show a plain-English summary here.', 'webcare' ) . '</p>';
}

/* ------------------------------------------------------------------
 * Enquiry actions card
 * ---------------------------------------------------------------- */

// "Oct to Dec 2026" for a quarter from webcare_quarter_info().
function webcare_quarter_label( $info ) {
    $name = function ( $month_number ) {
        $stamp = gmmktime( 12, 0, 0, (int) $month_number, 15, 2020 );
        $text  = function_exists( 'wp_date' ) ? wp_date( 'M', $stamp, new DateTimeZone( 'UTC' ) ) : false;
        return is_string( $text ) ? $text : gmdate( 'M', $stamp );
    };
    return sprintf(
        /* translators: 1: first month, 2: last month, 3: year, e.g. "Oct to Dec 2026" */
        __( '%1$s to %2$s %3$s', 'webcare' ),
        $name( $info['first'] ),
        $name( $info['last'] ),
        (int) $info['year']
    );
}

// The day enquiry counting began, as "2026-08-20" ('' if not known yet).
function webcare_tracking_started_date() {
    webcare_ensure_tracking_started();
    $started = get_option( 'webcare_tracking_started', '' );
    return ( is_string( $started ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $started ) ) ? $started : '';
}

// The day the first page view was recorded, as "2026-08-20" ('' if none yet). Page views are
// dated separately from enquiries because they were added later.
function webcare_visits_started_date() {
    $started = get_option( 'webcare_visits_started', '' );
    return ( is_string( $started ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $started ) ) ? $started : '';
}

// A "2026-08-20" date written the way the site's settings show dates ('' if not given).
function webcare_date_text( $date ) {
    if ( '' !== $date && function_exists( 'mysql2date' ) ) {
        return (string) mysql2date( get_option( 'date_format' ), $date . ' 00:00:00' );
    }
    return $date;
}

// The enquiry start day, written out.
function webcare_tracking_since_text() {
    return webcare_date_text( webcare_tracking_started_date() );
}

// A stored page address made readable for people ("/caf%C3%A9" shows as "/café"). Still escaped
// by the caller. If it isn't valid text once decoded, the original address is shown instead.
function webcare_readable_path( $path ) {
    $path    = (string) $path;
    $decoded = wp_check_invalid_utf8( rawurldecode( $path ) );
    return ( '' !== $decoded ) ? $decoded : $path;
}

// A whole number as a percentage of a total, e.g. 62.
function webcare_percent( $part, $total ) {
    return ( $total > 0 ) ? (int) round( ( $part / $total ) * 100 ) : 0;
}

// One card, two parts: visitor numbers, then enquiry actions, then a single privacy note.
function webcare_render_activity_section() {
    $visits_on = function_exists( 'webcare_visits_enabled' ) && webcare_visits_enabled();
    $clicks_on = function_exists( 'webcare_tracking_enabled' ) && webcare_tracking_enabled();

    if ( ! $visits_on && ! $clicks_on ) {
        echo '<p>' . esc_html__( 'Visitor and enquiry tracking is switched off on this website.', 'webcare' ) . '</p>';
        return;
    }

    if ( $visits_on ) {
        webcare_render_visitor_section();
    }
    if ( $clicks_on ) {
        webcare_render_enquiry_section();
    }

    echo '<p class="webcare-help"><strong>' . esc_html__( 'Privacy:', 'webcare' ) . '</strong> ' . esc_html__( 'These figures are anonymous totals. No cookies and nothing stored on visitors\' devices; no names or contact details. We keep monthly totals and a list of your most-viewed page addresses. To limit spam, a one-way scrambled code based on the visitor\'s connection is kept briefly (normally 10 minutes) and then deleted. If your privacy statement lists what you measure, you may like to add: "anonymous counts of visits and page views, and of clicks on phone, email and booking links".', 'webcare' ) . '</p>';
}

// Part one: visitors.
function webcare_render_visitor_section() {
    // Page views have their own start day. Until the first one is recorded there is no date, so last
    // quarter only shows figures if there happen to be some.
    $started       = webcare_visits_started_date();
    $this_info     = webcare_quarter_info( 0 );
    $last_info     = webcare_quarter_info( -1 );
    $this_v        = webcare_get_quarter_visits( 0 );
    $last_v        = webcare_get_quarter_visits( -1 );
    $started_month = ( '' !== $started ) ? str_replace( '-', '_', substr( $started, 0, 7 ) ) : '';
    if ( '' !== $started_month ) {
        $last_none = ( $last_info['to_month'] < $started_month );
    } else {
        $last_none = ( $last_v['views'] < 1 && $last_v['visits'] < 1 );
    }

    echo '<h3>' . esc_html__( 'Visitors', 'webcare' ) . '</h3>';
    ?>
    <table class="webcare-stats">
        <thead>
            <tr>
                <th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Measure', 'webcare' ); ?></span></th>
                <th scope="col"><?php echo esc_html( sprintf( /* translators: %s: quarter such as "Oct to Dec 2026" */ __( 'This quarter (%s)', 'webcare' ), webcare_quarter_label( $this_info ) ) ); ?></th>
                <th scope="col"><?php echo esc_html( sprintf( /* translators: %s: quarter such as "Jul to Sep 2026" */ __( 'Last quarter (%s)', 'webcare' ), webcare_quarter_label( $last_info ) ) ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <th scope="row"><?php echo esc_html__( 'Visits', 'webcare' ); ?></th>
                <td><?php echo esc_html( number_format_i18n( $this_v['visits'] ) ); ?></td>
                <td><?php echo $last_none ? '&mdash;' : esc_html( number_format_i18n( $last_v['visits'] ) ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__( 'Page views', 'webcare' ); ?></th>
                <td><?php echo esc_html( number_format_i18n( $this_v['views'] ) ); ?></td>
                <td><?php echo $last_none ? '&mdash;' : esc_html( number_format_i18n( $last_v['views'] ) ); ?></td>
            </tr>
        </tbody>
    </table>
    <?php
    if ( $this_v['visits'] < 1 && $this_v['views'] < 1 ) {
        echo '<p class="webcare-help">' . esc_html__( 'No visits counted yet this quarter.', 'webcare' ) . '</p>';
    } else {
        // The home page almost always tops the list and hides the more telling pages, so it is shown
        // on its own line and the "most-viewed" list covers the other pages only.
        $home_path  = webcare_normalise_path( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
        $home_path  = ( '' === $home_path ) ? '/' : $home_path;
        $home_views = isset( $this_v['pages'][ $home_path ] ) ? (int) $this_v['pages'][ $home_path ] : 0;
        $others     = $this_v['pages'];
        unset( $others[ $home_path ] );

        echo '<p><strong>' . esc_html__( 'Home page:', 'webcare' ) . '</strong> ' . esc_html(
            sprintf(
                /* translators: %s: number of page views */
                _n( '%s view', '%s views', $home_views, 'webcare' ),
                number_format_i18n( $home_views )
            )
        ) . '</p>';

        // Most-viewed other pages (the address is shown as plain text).
        echo '<h4>' . esc_html__( 'Most-viewed other pages this quarter', 'webcare' ) . '</h4>';
        $top = array_slice( $others, 0, 5, true );
        if ( empty( $top ) ) {
            echo '<p class="webcare-help">' . esc_html__( 'No other pages viewed yet.', 'webcare' ) . '</p>';
        } else {
            echo '<ol class="webcare-top-pages">';
            foreach ( $top as $path => $views ) {
                echo '<li><code>' . esc_html( webcare_readable_path( $path ) ) . '</code> &ndash; ' . esc_html(
                    sprintf(
                        /* translators: %s: number of page views */
                        _n( '%s view', '%s views', (int) $views, 'webcare' ),
                        number_format_i18n( (int) $views )
                    )
                ) . '</li>';
            }
            echo '</ol>';
        }

        // Where visits came from, as a share of all visits.
        $source_labels = [
            'search' => __( 'Search engines', 'webcare' ),
            'ai'     => __( 'AI assistants', 'webcare' ),
            'social' => __( 'Social media', 'webcare' ),
            'other'  => __( 'Other websites', 'webcare' ),
            'direct' => __( 'Typed in or bookmarked', 'webcare' ),
        ];
        $source_total = array_sum( $this_v['sources'] );
        echo '<h4>' . esc_html__( 'Where visits came from', 'webcare' ) . '</h4>';
        if ( $source_total < 1 ) {
            echo '<p class="webcare-help">' . esc_html__( 'No visits counted yet this quarter.', 'webcare' ) . '</p>';
        } else {
            echo '<ul class="webcare-list">';
            foreach ( $source_labels as $key => $label ) {
                echo '<li>' . esc_html( $label ) . ': ' . esc_html( number_format_i18n( $this_v['sources'][ $key ] ) ) . ' (' . esc_html( (string) webcare_percent( $this_v['sources'][ $key ], $source_total ) ) . '%)</li>';
            }
            echo '</ul>';
        }

        // Which kind of device (counted once per visit).
        $device_labels = [
            'mobile'  => __( 'Mobile', 'webcare' ),
            'tablet'  => __( 'Tablet', 'webcare' ),
            'desktop' => __( 'Desktop', 'webcare' ),
        ];
        $device_total = array_sum( $this_v['devices'] );
        echo '<h4>' . esc_html__( 'Devices', 'webcare' ) . '</h4>';
        if ( $device_total < 1 ) {
            echo '<p class="webcare-help">' . esc_html__( 'No visits counted yet this quarter.', 'webcare' ) . '</p>';
        } else {
            $parts = [];
            foreach ( $device_labels as $key => $label ) {
                $parts[] = $label . ' ' . webcare_percent( $this_v['devices'][ $key ], $device_total ) . '%';
            }
            echo '<p>' . esc_html( implode( ' · ', $parts ) ) . '</p>';
        }
    }

    if ( ! empty( $this_v['capped'] ) ) {
        echo '<p class="webcare-help">' . esc_html__( 'Some visits this quarter weren\'t counted because of unusually heavy traffic (spam protection).', 'webcare' ) . '</p>';
    }

    $since = webcare_date_text( $started );
    $note  = __( 'Visits are counted when someone arrives from outside your website; they aren\'t a count of unique people.', 'webcare' );
    if ( '' !== $since ) {
        /* translators: 1: date counting began, 2: explanation of visits */
        $note = sprintf( __( 'Counted since tracking started on %1$s. %2$s', 'webcare' ), $since, $note );
    }
    echo '<p class="webcare-help">' . esc_html( $note ) . '</p>';
}

// Part two: enquiry actions.
function webcare_render_enquiry_section() {
    $started = webcare_tracking_started_date();

    $this_info = webcare_quarter_info( 0 );
    $last_info = webcare_quarter_info( -1 );
    $this_qtr  = webcare_get_quarter_counts( 0 );
    $last_qtr  = webcare_get_quarter_counts( -1 );

    // A quarter that ended before counting began has nothing to show.
    $started_month    = ( '' !== $started ) ? str_replace( '-', '_', substr( $started, 0, 7 ) ) : '';
    $last_not_tracked = ( '' !== $started_month && $last_info['to_month'] < $started_month );

    echo '<h3>' . esc_html__( 'Enquiry actions', 'webcare' ) . '</h3>';

    $rows = [
        'phone'   => __( 'Phone number clicks', 'webcare' ),
        'email'   => __( 'Email link clicks', 'webcare' ),
        'booking' => __( 'Online booking link clicks', 'webcare' ),
        'form'    => __( 'Contact form sends', 'webcare' ),
    ];
    ?>
    <table class="webcare-stats">
        <thead>
            <tr>
                <th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Action', 'webcare' ); ?></span></th>
                <th scope="col"><?php echo esc_html( sprintf( /* translators: %s: quarter such as "Oct to Dec 2026" */ __( 'This quarter (%s)', 'webcare' ), webcare_quarter_label( $this_info ) ) ); ?></th>
                <th scope="col"><?php echo esc_html( sprintf( /* translators: %s: quarter such as "Jul to Sep 2026" */ __( 'Last quarter (%s)', 'webcare' ), webcare_quarter_label( $last_info ) ) ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $type => $label ) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $label ); ?></th>
                    <td><?php echo esc_html( number_format_i18n( $this_qtr[ $type ] ) ); ?></td>
                    <td><?php echo $last_not_tracked ? '&mdash;' : esc_html( number_format_i18n( $last_qtr[ $type ] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $since = webcare_tracking_since_text();
    if ( '' !== $since ) {
        echo '<p class="webcare-help">' . esc_html(
            sprintf(
                /* translators: %s: date counting began */
                __( 'Counted since tracking started on %s. Clicks show interest — a booking click isn\'t a confirmed appointment.', 'webcare' ),
                $since
            )
        ) . '</p>';
    } else {
        echo '<p class="webcare-help">' . esc_html__( 'Clicks show interest — a booking click isn\'t a confirmed appointment.', 'webcare' ) . '</p>';
    }

}

/* ------------------------------------------------------------------
 * Connection to APM card (Administrators only)
 * ---------------------------------------------------------------- */

// The secret key APM's health check app uses to fetch this website's figures. Shown only to
// Administrators (manage_options), never to Editors. The key is made the first time an Administrator looks.
function webcare_render_connection_card() {
    if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'webcare_get_connection_key' ) ) {
        return;
    }

    try {
        $feed_on = webcare_report_feed_enabled();
        $key     = $feed_on ? webcare_get_connection_key( true ) : '';

        $last      = get_option( 'webcare_feed_last_fetch', 0 );
        $last_text = __( 'not yet', 'webcare' );
        if ( is_numeric( $last ) && (int) $last > 0 ) {
            $format = trim( (string) get_option( 'date_format', '' ) . ' ' . (string) get_option( 'time_format', '' ) );
            $when   = wp_date( '' !== $format ? $format : 'j F Y, H:i', (int) $last );
            if ( is_string( $when ) && '' !== $when ) {
                $last_text = $when;
            }
        }
    } catch ( \Throwable $e ) {
        return;
    }
    ?>
    <div class="webcare-card" id="webcare-connection">
        <h2><?php echo esc_html__( 'Connection to APM', 'webcare' ); ?></h2>
        <?php if ( ! $feed_on ) : ?>
            <p><?php echo esc_html__( 'The connection to APM is switched off on this website.', 'webcare' ); ?></p>
        <?php elseif ( '' === $key ) : ?>
            <p><?php echo esc_html__( 'The connection key could not be created. Please contact APM.', 'webcare' ); ?></p>
        <?php else : ?>
            <p class="webcare-help"><?php echo esc_html__( 'APM uses this to fetch your website figures for your quarterly report. Keep it private.', 'webcare' ); ?></p>
            <p>
                <label class="screen-reader-text" for="webcare-connection-key"><?php echo esc_html__( 'Connection key', 'webcare' ); ?></label>
                <input type="text" id="webcare-connection-key" class="large-text code" readonly="readonly" value="<?php echo esc_attr( $key ); ?>" onfocus="this.select();">
            </p>
            <p class="webcare-help"><?php echo esc_html( sprintf( /* translators: %s: date and time, or "not yet" */ __( 'Last fetched by APM: %s', 'webcare' ), $last_text ) ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Create a new key? The current key stops working straight away, and APM cannot fetch your figures until it is given the new one.', 'webcare' ) ); ?>');">
                <input type="hidden" name="action" value="webcare_new_key">
                <?php wp_nonce_field( 'webcare_new_key' ); ?>
                <p><button type="submit" class="button"><?php echo esc_html__( 'Create a new key', 'webcare' ); ?></button></p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function webcare_render_service_section() {
    $info = webcare_service_info();
    $get  = function ( $key ) use ( $info ) {
        return isset( $info[ $key ] ) ? $info[ $key ] : '';
    };

    if ( $get( 'name' ) ) {
        echo '<p><strong>' . esc_html( $get( 'name' ) ) . '</strong></p>';
    }

    $lists = [
        [ 'included_title', 'included' ],
    ];
    foreach ( $lists as $pair ) {
        $items = $get( $pair[1] );
        if ( empty( $items ) || ! is_array( $items ) ) {
            continue;
        }
        if ( $get( $pair[0] ) ) {
            echo '<h3>' . esc_html( $get( $pair[0] ) ) . '</h3>';
        }
        echo '<ul class="webcare-list">';
        foreach ( $items as $item ) {
            if ( '' !== (string) $item ) {
                echo '<li>' . esc_html( $item ) . '</li>';
            }
        }
        echo '</ul>';
    }

    if ( $get( 'response_times' ) ) {
        echo '<h3>' . esc_html__( 'Response times', 'webcare' ) . '</h3>';
        echo '<p>' . esc_html( $get( 'response_times' ) ) . '</p>';
    }

    if ( $get( 'bigger' ) ) {
        if ( $get( 'bigger_title' ) ) {
            echo '<h3>' . esc_html( $get( 'bigger_title' ) ) . '</h3>';
        }
        echo '<p>' . esc_html( $get( 'bigger' ) ) . '</p>';
    }

    if ( $get( 'email' ) || $get( 'phone' ) ) {
        echo '<h3>' . esc_html__( 'Contact us', 'webcare' ) . '</h3><p>';
        if ( $get( 'email' ) ) {
            echo esc_html__( 'Email:', 'webcare' ) . ' <a href="' . esc_url( 'mailto:' . $get( 'email' ) ) . '">' . esc_html( $get( 'email' ) ) . '</a>';
        }
        if ( $get( 'email' ) && $get( 'phone' ) ) {
            echo '<br>';
        }
        if ( $get( 'phone' ) ) {
            echo esc_html__( 'Phone:', 'webcare' ) . ' ' . esc_html( $get( 'phone' ) );
        }
        echo '</p>';
    }

    if ( $get( 'ownership' ) ) {
        echo '<h3>' . esc_html__( 'Your website belongs to you', 'webcare' ) . '</h3>';
        echo '<p>' . esc_html( $get( 'ownership' ) ) . '</p>';
    }
}

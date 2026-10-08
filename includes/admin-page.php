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
            <h2><?php echo esc_html__( 'Enquiry actions this quarter', 'webcare' ); ?></h2>
            <?php webcare_render_enquiry_section(); ?>
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

// Is Independent Analytics (the free visitor-numbers plugin we install) running?
function webcare_independent_analytics_active() {
    if ( defined( 'IAWP_VERSION' ) || class_exists( 'IAWP\\Plugin', false ) ) {
        return true;
    }
    return function_exists( 'is_plugin_active' ) && is_plugin_active( 'independent-analytics/iawp.php' );
}

function webcare_render_enquiry_section() {
    if ( ! function_exists( 'webcare_tracking_enabled' ) || ! webcare_tracking_enabled() ) {
        echo '<p>' . esc_html__( 'Enquiry tracking is switched off on this website.', 'webcare' ) . '</p>';
        return;
    }

    webcare_ensure_tracking_started();
    $started = get_option( 'webcare_tracking_started', '' );
    $started = ( is_string( $started ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $started ) ) ? $started : '';

    $this_info = webcare_quarter_info( 0 );
    $last_info = webcare_quarter_info( -1 );
    $this_qtr  = webcare_get_quarter_counts( 0 );
    $last_qtr  = webcare_get_quarter_counts( -1 );

    // A quarter that ended before counting began has nothing to show.
    $started_month    = ( '' !== $started ) ? str_replace( '-', '_', substr( $started, 0, 7 ) ) : '';
    $last_not_tracked = ( '' !== $started_month && $last_info['to_month'] < $started_month );

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
    $since = $started;
    if ( '' !== $started && function_exists( 'mysql2date' ) ) {
        $since = mysql2date( get_option( 'date_format' ), $started . ' 00:00:00' );
    }
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

    echo '<p>';
    if ( webcare_independent_analytics_active() ) {
        echo esc_html__( 'Visitor numbers are recorded by Independent Analytics — see Dashboard → Analytics', 'webcare' );
    } else {
        echo esc_html__( 'Visitor numbers: not set up yet — ask APM', 'webcare' );
    }
    echo '</p>';

    echo '<p class="webcare-help"><strong>' . esc_html__( 'Privacy:', 'webcare' ) . '</strong> ' . esc_html__( 'These figures are anonymous totals. No cookies, no names or contact details, and no record of which pages people visit. To stop spam, a temporary scrambled code based on the visitor\'s connection is set to expire after 10 minutes. If your privacy statement lists what you measure, you may like to add: "anonymous counts of clicks on phone, email and booking links".', 'webcare' ) . '</p>';
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

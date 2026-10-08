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

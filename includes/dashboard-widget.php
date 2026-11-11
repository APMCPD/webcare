<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function webcare_register_dashboard_widget() {
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        return;
    }
    wp_add_dashboard_widget( 'webcare_dashboard_widget', __( 'Webcare Connect', 'webcare' ), 'webcare_render_dashboard_widget' );
}

// Kept light: no remote calls, so the Dashboard stays fast.
function webcare_render_dashboard_widget() {
    $support = webcare_get_support_email();

    // This quarter so far, in one line (reads the stored monthly totals only; no remote calls).
    $parts = [];
    if ( function_exists( 'webcare_visits_enabled' ) && webcare_visits_enabled() ) {
        $v       = webcare_get_quarter_visits( 0 );
        $parts[] = sprintf( /* translators: %s: number */ _n( '%s visit', '%s visits', (int) $v['visits'], 'webcare' ), number_format_i18n( (int) $v['visits'] ) );
    }
    if ( function_exists( 'webcare_tracking_enabled' ) && webcare_tracking_enabled() ) {
        $e       = webcare_enquiry_total( webcare_get_quarter_counts( 0 ) );
        $parts[] = sprintf( /* translators: %s: number */ _n( '%s enquiry', '%s enquiries', $e, 'webcare' ), number_format_i18n( $e ) );
    }
    if ( ! empty( $parts ) ) {
        echo '<p class="webcare-widget-figures"><strong>' . esc_html__( 'This quarter so far:', 'webcare' ) . '</strong> ' . esc_html( implode( ' · ', $parts ) ) . '</p>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=webcare#webcare-enquiries' ) ) . '">' . esc_html__( 'See your website figures', 'webcare' ) . '</a></p>';
    }
    echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=webcare#webcare-request' ) ) . '">' . esc_html__( 'Request a change', 'webcare' ) . '</a></p>';

    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=webcare-business' ) ) . '">' . esc_html__( 'Help Google & AI find you — check your business details', 'webcare' ) . '</a></p>';

    if ( $support ) {
        echo '<p>' . esc_html__( 'Or email us:', 'webcare' ) . ' <a href="' . esc_url( 'mailto:' . $support ) . '">' . esc_html( $support ) . '</a></p>';
    }
}

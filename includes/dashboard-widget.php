<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function webcare_register_dashboard_widget() {
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        return;
    }
    wp_add_dashboard_widget( 'webcare_dashboard_widget', __( 'Webcare', 'webcare' ), 'webcare_render_dashboard_widget' );
}

// Kept light: no remote calls, so the Dashboard stays fast.
function webcare_render_dashboard_widget() {
    $support = webcare_get_support_email();

    echo '<p>' . esc_html__( 'Website health checks are coming soon.', 'webcare' ) . '</p>';
    echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=webcare#webcare-request' ) ) . '">' . esc_html__( 'Request a change', 'webcare' ) . '</a></p>';

    if ( $support ) {
        echo '<p>' . esc_html__( 'Or email us:', 'webcare' ) . ' <a href="' . esc_url( 'mailto:' . $support ) . '">' . esc_html( $support ) . '</a></p>';
    }
}

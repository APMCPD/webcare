<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Updates from the public GitHub repo (releases tagged vX.Y.Z)
 * ---------------------------------------------------------------- */

function webcare_setup_updater() {
    $loader = WEBCARE_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $loader ) ) {
        return;
    }

    // A problem with the updater must never take a client site down.
    try {
        require_once $loader;

        if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/APMCPD/webcare/',
            WEBCARE_PATH . 'webcare.php',
            'webcare'
        );
        $checker->setBranch( 'master' );
        // Withdrawing a release must also withdraw its tag and branch as update sources.
        add_filter( $checker->getUniqueName( 'vcs_update_detection_strategies' ), function ( $strategies ) {
            return array_intersect_key( $strategies, [ 'latest_release' => true ] );
        } );
    } catch ( \Throwable $e ) {
        error_log( '[Webcare] Updater could not start: ' . $e->getMessage() );
    }
}
webcare_setup_updater();

define( 'WEBCARE_UPDATE_DELAY_DAYS', 3 );

function webcare_update_delay_days() {
    return max( 0, min( 30, (int) apply_filters( 'webcare_update_delay_days', WEBCARE_UPDATE_DELAY_DAYS ) ) );
}

function webcare_is_test_site() {
    return 'test' === get_option( 'webcare_update_ring' );
}

function webcare_update_seen_at( $version ) {
    $seen = get_option( 'webcare_update_seen' );
    if ( is_array( $seen ) && ( $seen['version'] ?? null ) === $version && is_int( $seen['at'] ?? null ) && $seen['at'] > 0 ) {
        return $seen['at'];
    }
    return 0;
}

function webcare_auto_update( $update, $item ) {
    try {
        if ( ! is_object( $item ) || ! isset( $item->plugin ) || plugin_basename( WEBCARE_PATH . 'webcare.php' ) !== $item->plugin ) {
            return $update;
        }
        $version = $item->new_version ?? '';
        if ( ! is_string( $version ) || '' === $version || webcare_is_test_site() ) {
            return true;
        }
        // Nothing newer to install (WordPress also asks about up-to-date plugins): don't start a clock.
        if ( version_compare( $version, WEBCARE_VERSION, '<=' ) ) {
            return true;
        }
        $at = webcare_update_seen_at( $version );
        if ( ! $at ) {
            update_option( 'webcare_update_seen', [ 'version' => $version, 'at' => time() ], false );
            return false;
        }
        return time() - $at >= webcare_update_delay_days() * DAY_IN_SECONDS;
    } catch ( \Throwable $e ) {
        return true;
    }
}
add_filter( 'auto_update_plugin', 'webcare_auto_update', 10, 2 );

function webcare_handle_update_ring() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorised', '', [ 'response' => 403 ] );
    }
    check_admin_referer( 'webcare_update_ring' );
    $ring = $_POST['webcare_update_ring'] ?? '';
    $ring = is_string( $ring ) ? wp_unslash( $ring ) : '';
    if ( ! in_array( $ring, [ 'test', 'standard' ], true ) ) {
        webcare_redirect( 'update_ring_invalid' );
        return;
    }
    update_option( 'webcare_update_ring', $ring, false );
    webcare_redirect( 'update_ring_saved' );
}

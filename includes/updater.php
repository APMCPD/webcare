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
    } catch ( \Throwable $e ) {
        error_log( '[Webcare] Updater could not start: ' . $e->getMessage() );
    }
}
webcare_setup_updater();

// Install Webcare updates automatically; leave every other plugin's setting alone.
function webcare_auto_update( $update, $item ) {
    if ( is_object( $item ) && isset( $item->plugin ) && plugin_basename( WEBCARE_PATH . 'webcare.php' ) === $item->plugin ) {
        return true;
    }
    return $update;
}
add_filter( 'auto_update_plugin', 'webcare_auto_update', 10, 2 );

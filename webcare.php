<?php
/**
 * Plugin Name: Webcare
 * Description: One place for your Webcare service details, website health and change requests.
 * Version: 1.0.0
 * Author: APM
 * License: GPL-2.0+
 * Text Domain: webcare
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * Changelog:
 * 1.0.0 - Initial release. Webcare page and dashboard widget, "Request a change" form
 *         (emails our support mailbox and confirms to the client), "Your service"
 *         information, and automatic updates from GitHub.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// If a second copy of this plugin is installed (e.g. an old folder left behind), stop here
// so the duplicate constants/functions don't crash the whole site.
if ( defined( 'WEBCARE_VERSION' ) ) {
    return;
}

// IMPORTANT: this number must always match the "Version:" line in the header above.
// Bump both together whenever you release an update.
define( 'WEBCARE_VERSION', '1.0.0' );
define( 'WEBCARE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WEBCARE_URL', plugin_dir_url( __FILE__ ) );

// Who can see and use Webcare: Editors and Administrators (Authors/Contributors cannot).
define( 'WEBCARE_CAPABILITY', 'edit_pages' );

require_once WEBCARE_PATH . 'includes/config.php';
require_once WEBCARE_PATH . 'includes/admin-page.php';
require_once WEBCARE_PATH . 'includes/dashboard-widget.php';
require_once WEBCARE_PATH . 'includes/request-form.php';
require_once WEBCARE_PATH . 'includes/updater.php';

// Menu page + styles.
add_action( 'admin_menu', 'webcare_register_menu' );
add_action( 'admin_enqueue_scripts', 'webcare_enqueue_assets' );

// Dashboard widget.
add_action( 'wp_dashboard_setup', 'webcare_register_dashboard_widget' );

// Form submission (logged-in users only - deliberately no "nopriv" version).
add_action( 'admin_post_webcare_request', 'webcare_handle_request' );

<?php
/**
 * Plugin Name: Webcare
 * Description: One place for your Webcare service details, website health and change requests.
 * Version: 1.1.0
 * Author: APM
 * License: GPL-2.0+
 * Text Domain: webcare
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * Changelog:
 * 1.1.0 - New "Business details" page (Webcare menu): clients enter their phone, address, opening hours
 *         and services once, and Webcare publishes them invisibly for Google and AI assistants
 *         (added to Yoast SEO's own markup, or printed on its own if no SEO plugin is active).
 * 1.0.2 - Support emails go to webcare@apmcpd.co.uk by default (wp-config setting now optional); safer service wording.
 * 1.0.1 - Final service wording for APM Webcare; health check described as quarterly.
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
define( 'WEBCARE_VERSION', '1.1.0' );

// Where change requests are emailed by default. A single site can override this by adding
// define( 'WEBCARE_SUPPORT_EMAIL', '...' ); to its wp-config.php (optional).
define( 'WEBCARE_DEFAULT_SUPPORT_EMAIL', 'webcare@apmcpd.co.uk' );
define( 'WEBCARE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WEBCARE_URL', plugin_dir_url( __FILE__ ) );

// Who can see and use Webcare: Editors and Administrators (Authors/Contributors cannot).
define( 'WEBCARE_CAPABILITY', 'edit_pages' );

require_once WEBCARE_PATH . 'includes/config.php';
require_once WEBCARE_PATH . 'includes/admin-page.php';
require_once WEBCARE_PATH . 'includes/dashboard-widget.php';
require_once WEBCARE_PATH . 'includes/request-form.php';
require_once WEBCARE_PATH . 'includes/updater.php';
require_once WEBCARE_PATH . 'includes/schema-output.php';
require_once WEBCARE_PATH . 'includes/business-details.php';

// Menu page + styles. (The Business details submenu runs just after the main menu exists.)
add_action( 'admin_menu', 'webcare_register_menu' );
add_action( 'admin_menu', 'webcare_register_business_menu', 11 );
add_action( 'admin_enqueue_scripts', 'webcare_enqueue_assets' );

// Dashboard widget.
add_action( 'wp_dashboard_setup', 'webcare_register_dashboard_widget' );

// Form submission (logged-in users only - deliberately no "nopriv" version).
add_action( 'admin_post_webcare_request', 'webcare_handle_request' );
add_action( 'admin_post_webcare_business', 'webcare_handle_business' );

// Business details for Google & AI. Both hooks are always registered; each one checks which
// SEO plugin is active at the moment it runs, so plugin load order doesn't matter.
// - Yoast SEO active: our details are added to Yoast's own schema (priority 20, after Yoast builds it).
// - No SEO plugin: we print our own block in the page head.
// - Any other SEO plugin (or Yoast Local SEO): we do nothing.
add_filter( 'wpseo_schema_graph', 'webcare_filter_yoast_graph', 20, 2 );
add_action( 'wp_head', 'webcare_output_business_schema', 20 );

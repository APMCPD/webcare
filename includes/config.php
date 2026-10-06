<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * wp-config.php settings
 * ---------------------------------------------------------------- */

/**
 * The address change requests are sent to. Set in wp-config.php:
 *     define( 'WEBCARE_SUPPORT_EMAIL', 'webcare@example.com' );
 * Returns '' if missing or not a valid email. We never fall back to the
 * site's admin email, so a request can't go to the wrong person.
 */
function webcare_get_support_email() {
    if ( ! defined( 'WEBCARE_SUPPORT_EMAIL' ) ) {
        return '';
    }
    $email = sanitize_email( (string) WEBCARE_SUPPORT_EMAIL );
    return is_email( $email ) ? $email : '';
}

/* ------------------------------------------------------------------
 * "Your service" content
 * ---------------------------------------------------------------- */

/**
 * ============================================================
 * EDIT THIS - shown on every client site.
 * Change the wording below, then release an update (see readme.txt).
 * Anything left empty ('') simply isn't shown.
 * ============================================================
 */
function webcare_service_info() {
    $info = [
        'name'           => 'APM Webcare',
        'included_title' => 'What\'s included',
        'included'       => [
            'Your website hosted on APM\'s servers',
            'Daily backups of your website',
            'WordPress, your theme and all plugins kept up to date by us',
            'Uptime monitoring, so we know if your site goes down',
            'Content updates on request – new practitioners, price changes, opening hours, photos and text',
            'A quarterly, plain-English website health check report',
            'Google Business Profile guidance and reminders',
        ],
        'response_times' => 'Send us what you\'d like changed, along with any wording or photos we\'ll need. We aim to update your site within 1–2 working days (Monday to Friday) once we have everything. Content updates are subject to fair use.',
        // Short version used in the confirmation email.
        'response_short' => 'We aim to update your site within 1–2 working days (Monday to Friday) once we have everything we need.',
        'bigger_title'   => 'Something bigger in mind?',
        'bigger'         => 'If you\'d like something beyond everyday updates, such as a new section or feature, just get in touch and we\'ll talk it through.',
        'email'          => webcare_get_support_email(),
        'phone'          => '',
        'ownership'      => 'Your website is yours. Your domain stays in your name – we simply connect it to our hosting – and you can ask for a full copy of your site at any time. You can also request full Administrator access; if changes made with admin access cause problems, fixing them is chargeable.',
    ];

    // Lets us override the content later without editing this file.
    return apply_filters( 'webcare_service_info', $info );
}

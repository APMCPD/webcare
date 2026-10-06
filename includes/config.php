<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Support address
 * ---------------------------------------------------------------- */

/**
 * The address change requests are sent to.
 * By default this is WEBCARE_DEFAULT_SUPPORT_EMAIL (set in webcare.php). Nothing
 * needs adding to wp-config.php. OPTIONALLY, to send one site's requests elsewhere:
 *     define( 'WEBCARE_SUPPORT_EMAIL', 'webcare@example.com' );
 * If that override is invalid it is ignored (and logged once per request) and the
 * default is used. Returns '' only if the default itself is invalid - the "not set up"
 * safety net. We never fall back to the site's admin email, so a request can't go
 * to the wrong person.
 */
function webcare_get_support_email() {
    static $logged = false;

    if ( defined( 'WEBCARE_SUPPORT_EMAIL' ) ) {
        $email = sanitize_email( (string) WEBCARE_SUPPORT_EMAIL );
        if ( is_email( $email ) ) {
            return $email;
        }
        if ( ! $logged ) {
            $logged = true;
            error_log( 'Webcare: WEBCARE_SUPPORT_EMAIL in wp-config.php is not a valid email address, so it was ignored. Using the built-in default instead.' );
        }
    }

    $default = defined( 'WEBCARE_DEFAULT_SUPPORT_EMAIL' ) ? sanitize_email( (string) WEBCARE_DEFAULT_SUPPORT_EMAIL ) : '';
    return is_email( $default ) ? $default : '';
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
            'We look after WordPress, theme and plugin updates for you',
            'Uptime monitoring, so we know if your site goes down',
            'Content updates on request – new practitioners, price changes, opening hours, photos and text',
            'A quarterly, plain-English website health check report',
            'Google Business Profile guidance and reminders',
        ],
        'response_times' => 'Send us what you\'d like changed, along with any wording or photos we\'ll need. We aim to update your site within 1–2 working days (Monday to Friday) once we have everything. Content updates are subject to fair use.',
        // Short version used in the confirmation email.
        'response_short' => 'We aim to update your site within 1–2 working days (Monday to Friday) once we have everything we need.',
        'bigger_title'   => 'Something bigger in mind?',
        'bigger'         => 'If you\'d like something beyond everyday updates, such as a new section or feature, just get in touch – we\'ll talk it through and let you know if it would be quoted separately.',
        'email'          => webcare_get_support_email(),
        'phone'          => '',
        'ownership'      => 'Your website is yours. Your domain stays in your name – we simply connect it to our hosting – and you can ask for a full copy of your site at any time. You can also request full Administrator access; if changes made with admin access cause problems, fixing them is chargeable.',
    ];

    // Lets us override the content later without editing this file.
    return apply_filters( 'webcare_service_info', $info );
}

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
        'name'           => 'Webcare',
        'included_title' => 'What\'s included',
        'included'       => [
            'Website hosting',
            'Daily backups',
            'Security and software updates',
            'Uptime monitoring',
            'Small content changes on request',
        ],
        'quoted_title'   => 'Quoted separately',
        'quoted'         => [
            'New pages or features',
            'Redesigns',
            'Anything larger than a small content change',
        ],
        'response_times' => 'We reply within 1 working day; urgent issues the same day.',
        'email'          => webcare_get_support_email(),
        'phone'          => '',
        'ownership'      => 'Your website is yours. Your domain is registered in your name, and you can ask for a full copy of your site at any time. You can also request full Administrator access — if changes made with admin access cause problems, fixing them is chargeable.',
    ];

    // Lets us override the content later without editing this file.
    return apply_filters( 'webcare_service_info', $info );
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------- */

// The "How urgent is it?" choices. The keys are what we accept; anything else is rejected.
function webcare_urgency_options() {
    return [
        'convenient' => __( 'Whenever convenient', 'webcare' ),
        'few_days'   => __( 'Within a few days', 'webcare' ),
        'urgent'     => __( 'Urgent — something is broken', 'webcare' ),
    ];
}

// Length that copes with accents/emoji when the mbstring extension exists.
function webcare_strlen( $text ) {
    return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
}

// Remove line breaks so a value can never inject extra email headers.
function webcare_strip_newlines( $text ) {
    return str_replace( [ "\r", "\n" ], ' ', $text );
}

// Light clean-up for free text: keeps the user's wording exactly (no tag-stripping or
// entity changes), but removes invalid UTF-8 and hidden control characters.
// $multiline = true keeps line breaks and tabs; false turns them into spaces.
function webcare_clean_text( $text, $multiline ) {
    $text = wp_check_invalid_utf8( (string) $text, true ); // Invalid bytes are removed, not guessed at.
    $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
    $clean = preg_replace( '/[^\P{C}\n\t]/u', '', $text );
    if ( null !== $clean ) { // null means the regex failed; keep the text rather than lose it.
        $text = $clean;
    }
    if ( ! $multiline ) {
        $text = str_replace( [ "\n", "\t" ], ' ', $text );
    }
    return trim( $text );
}

// Where we park what the user typed if something goes wrong (5 minutes, this user only).
function webcare_form_transient_key( $user_id ) {
    return 'webcare_form_' . (int) $user_id;
}

/* ------------------------------------------------------------------
 * Form (shown on the Webcare page)
 * ---------------------------------------------------------------- */

function webcare_render_request_form() {
    $support = webcare_get_support_email();

    // Not set up: no form for anyone. Admins also get the fix-it instructions.
    if ( '' === $support ) {
        echo '<p>' . esc_html__( 'Online requests aren\'t set up yet — please contact us directly.', 'webcare' ) . '</p>';
        if ( current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__( 'Administrator note: the support address is built into the plugin, so this should not normally appear - the built-in address appears to be invalid, so please contact APM. Adding the line below to wp-config.php (above the "That\'s all, stop editing" line) is an optional override that can also switch the form on:', 'webcare' )
                . '</p><p><code>' . esc_html( "define( 'WEBCARE_SUPPORT_EMAIL', 'webcare@example.com' );" ) . '</code></p></div>';
        }
        return;
    }

    $user = wp_get_current_user();

    // Defaults, replaced by what the user previously typed if there was a problem.
    $values = [
        'name'    => $user->display_name,
        'email'   => $user->user_email,
        'message' => '',
        'page'    => '',
        'urgency' => 'convenient',
    ];
    $saved = get_transient( webcare_form_transient_key( $user->ID ) );
    if ( is_array( $saved ) ) {
        foreach ( $values as $key => $default ) {
            if ( isset( $saved[ $key ] ) && is_string( $saved[ $key ] ) ) {
                $values[ $key ] = $saved[ $key ];
            }
        }
        delete_transient( webcare_form_transient_key( $user->ID ) );
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="webcare_request">
        <?php wp_nonce_field( 'webcare_request' ); ?>

        <div class="webcare-field">
            <label for="webcare-name"><?php echo esc_html__( 'Your name', 'webcare' ); ?></label>
            <input type="text" id="webcare-name" name="webcare_name" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="200" required>
        </div>

        <div class="webcare-field">
            <label for="webcare-email"><?php echo esc_html__( 'Your email', 'webcare' ); ?></label>
            <input type="email" id="webcare-email" name="webcare_email" value="<?php echo esc_attr( $values['email'] ); ?>" maxlength="200" required>
        </div>

        <div class="webcare-field">
            <label for="webcare-message"><?php echo esc_html__( 'What would you like changed?', 'webcare' ); ?></label>
            <textarea id="webcare-message" name="webcare_message" rows="6" maxlength="5000" required><?php echo esc_textarea( $values['message'] ); ?></textarea>
        </div>

        <div class="webcare-field">
            <label for="webcare-page"><?php echo esc_html__( 'Which page is it on? (optional)', 'webcare' ); ?></label>
            <input type="text" id="webcare-page" name="webcare_page" value="<?php echo esc_attr( $values['page'] ); ?>" maxlength="500">
            <span class="webcare-help"><?php echo esc_html__( 'You can paste the web address or just describe where it is.', 'webcare' ); ?></span>
        </div>

        <div class="webcare-field">
            <label for="webcare-urgency"><?php echo esc_html__( 'How urgent is it?', 'webcare' ); ?></label>
            <select id="webcare-urgency" name="webcare_urgency">
                <?php foreach ( webcare_urgency_options() as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['urgency'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Send request', 'webcare' ); ?></button></p>
    </form>
    <?php
}

/* ------------------------------------------------------------------
 * Form handler (admin_post_webcare_request - logged-in users only)
 * ---------------------------------------------------------------- */

// Always ends with a redirect back to the Webcare page, so refreshing never resends.
function webcare_redirect( $msg ) {
    wp_safe_redirect( add_query_arg( 'webcare_msg', $msg, admin_url( 'admin.php?page=webcare' ) ) );
    exit;
}

function webcare_handle_request() {
    // Checked on every request, not just when the menu is shown.
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        wp_die( 'Unauthorised', 403 );
    }
    check_admin_referer( 'webcare_request' );

    $support = webcare_get_support_email();
    if ( '' === $support ) {
        webcare_redirect( 'not_setup' );
    }

    $user = wp_get_current_user();

    // --- Read and clean what was typed ---
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
    $name    = trim( webcare_strip_newlines( sanitize_text_field( wp_unslash( isset( $_POST['webcare_name'] ) ? $_POST['webcare_name'] : '' ) ) ) );
    $email   = trim( webcare_strip_newlines( sanitize_text_field( wp_unslash( isset( $_POST['webcare_email'] ) ? $_POST['webcare_email'] : '' ) ) ) );
    // Message and page keep the user's exact wording (escaped wherever shown; emails are plain text).
    $message = webcare_clean_text( wp_unslash( isset( $_POST['webcare_message'] ) ? $_POST['webcare_message'] : '' ), true );
    $page    = webcare_clean_text( wp_unslash( isset( $_POST['webcare_page'] ) ? $_POST['webcare_page'] : '' ), false );
    $urgency = sanitize_key( wp_unslash( isset( $_POST['webcare_urgency'] ) ? $_POST['webcare_urgency'] : '' ) );
    // phpcs:enable

    // Keep what they typed (capped in size) so a mistake doesn't lose their message.
    $keep = function () use ( $user, $name, $email, $message, $page, $urgency ) {
        set_transient(
            webcare_form_transient_key( $user->ID ),
            [
                'name'    => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1000, 'UTF-8' ) : substr( $name, 0, 1000 ),
                'email'   => function_exists( 'mb_substr' ) ? mb_substr( $email, 0, 1000, 'UTF-8' ) : substr( $email, 0, 1000 ),
                'message' => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 10000, 'UTF-8' ) : substr( $message, 0, 10000 ),
                'page'    => function_exists( 'mb_substr' ) ? mb_substr( $page, 0, 1000, 'UTF-8' ) : substr( $page, 0, 1000 ),
                'urgency' => $urgency,
            ],
            5 * MINUTE_IN_SECONDS
        );
    };

    // --- Validate ---
    $urgency_options = webcare_urgency_options();
    if ( ! isset( $urgency_options[ $urgency ] ) ) {
        $urgency = 'convenient';
    }

    if ( '' === $name || '' === $message || ! is_email( $email ) ) {
        $keep();
        webcare_redirect( 'invalid' );
    }

    if ( webcare_strlen( $name ) > 200 || webcare_strlen( $email ) > 200
        || webcare_strlen( $message ) > 5000 || webcare_strlen( $page ) > 500 ) {
        $keep();
        webcare_redirect( 'too_long' );
    }

    // --- Rate limit: one request per user per minute ---
    $rate_key = 'webcare_rl_' . $user->ID;
    if ( false !== get_transient( $rate_key ) ) {
        $keep();
        webcare_redirect( 'rate_limited' );
    }

    // --- Build the email to our support mailbox ---
    $domain = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( ! $domain ) {
        $domain = get_bloginfo( 'name' );
    }

    $is_urgent = ( 'urgent' === $urgency );
    $subject   = $is_urgent
        ? '[Webcare] URGENT change request – ' . $domain
        : '[Webcare] Change request – ' . $domain;
    $subject   = webcare_strip_newlines( $subject );

    // Work out a readable role name (e.g. "Editor").
    $role_label = '';
    if ( ! empty( $user->roles ) ) {
        $role_key   = reset( $user->roles );
        $wp_roles   = wp_roles();
        $role_label = isset( $wp_roles->role_names[ $role_key ] ) ? translate_user_role( $wp_roles->role_names[ $role_key ] ) : $role_key;
    }

    $body  = 'Site: ' . get_bloginfo( 'name' ) . "\n";
    $body .= 'Site URL: ' . home_url() . "\n";
    $body .= 'From: ' . $name . ' <' . $email . '>' . "\n";
    $body .= 'WordPress role: ' . $role_label . "\n";
    $body .= 'Urgency: ' . $urgency_options[ $urgency ] . "\n";
    $body .= 'Page: ' . ( '' !== $page ? $page : '(not given)' ) . "\n";
    $body .= 'Sent: ' . wp_date( 'j F Y, H:i' ) . "\n\n";
    $body .= "Request:\n" . $message . "\n";

    // Strip characters that could break the Reply-To header.
    $reply_name = trim( str_replace( [ '<', '>', '"' ], '', webcare_strip_newlines( $name ) ) );
    $headers    = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $reply_name . ' <' . $email . '>',
    ];
    // We deliberately don't set From: - keeping the site default protects email delivery (SPF/DKIM).

    // Start the one-minute wait BEFORE sending, so a quick double-click can't send twice.
    set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

    $sent = wp_mail( $support, $subject, $body, $headers );

    if ( ! $sent ) {
        // Didn't go: lift the wait so they can try again straight away.
        delete_transient( $rate_key );
        error_log( '[Webcare] Support email failed to send for ' . $domain . '.' );
        $keep();
        webcare_redirect( 'send_failed' );
    }

    delete_transient( webcare_form_transient_key( $user->ID ) );

    // --- Confirmation to the client (a failure here is logged, not shown) ---
    $info     = webcare_service_info();
    $response = isset( $info['response_short'] ) ? (string) $info['response_short'] : '';

    $confirm  = "Thanks — we've received your request and will reply to " . $email . ".\n";
    if ( '' !== $response ) {
        $confirm .= $response . "\n";
    }
    $confirm .= "\nHere's a copy of what you asked for:\n\n";
    $confirm .= 'Page: ' . ( '' !== $page ? $page : '(not given)' ) . "\n";
    $confirm .= 'How urgent: ' . $urgency_options[ $urgency ] . "\n\n";
    $confirm .= $message . "\n\n";
    $confirm .= "— Webcare\n";

    // Safety: the confirmation only ever goes to the logged-in account's own address,
    // never to whatever was typed in the form (otherwise it could be used to email strangers).
    $account_email = $user->user_email;
    if ( is_email( $account_email ) ) {
        $confirm_ok = wp_mail(
            $account_email,
            "We've received your Webcare request",
            $confirm,
            [
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $support,
            ]
        );
        if ( ! $confirm_ok ) {
            error_log( '[Webcare] Confirmation email to client failed for ' . $domain . ' (the request itself was delivered).' );
        }
    } else {
        error_log( '[Webcare] Confirmation skipped for ' . $domain . ': the account email address is not valid.' );
    }

    webcare_redirect( 'sent' );
}

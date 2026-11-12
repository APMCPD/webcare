<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Email check: can this website send emails?
 *
 * Three parts, all shown on the Webcare page:
 *  A. A "Send a test email" button (to the logged-in user's own address only).
 *  B. A count of emails the website failed to send (any email, not just ours).
 *  C. Administrators only: which email plugin sends the mail, and whether the
 *     sending domain has SPF / DMARC records.
 *
 * Nothing here contacts any outside service except normal DNS lookups.
 * ---------------------------------------------------------------- */

/* ------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------- */

// Make an error message safe to store and show: email addresses are replaced, line breaks and
// hidden characters are removed, and it is cut to 300 characters.
function webcare_redact_mail_error( $text ) {
    if ( ! is_scalar( $text ) ) {
        return '';
    }
    $text  = wp_check_invalid_utf8( (string) $text, true );
    $clean = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
    if ( null !== $clean ) {
        $text = $clean;
    }
    $clean = preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/u', '[email address]', $text );
    if ( null !== $clean ) {
        $text = $clean;
    }
    $text = trim( $text );
    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $text, 0, 300, 'UTF-8' );
    }
    return substr( $text, 0, 300 );
}

// A timestamp written the way the site's settings show dates and times ('' if there isn't one).
function webcare_email_time_text( $timestamp ) {
    if ( ! is_numeric( $timestamp ) || (int) $timestamp < 1 ) {
        return '';
    }
    $format = trim( (string) get_option( 'date_format', '' ) . ' ' . (string) get_option( 'time_format', '' ) );
    $when   = wp_date( '' !== $format ? $format : 'j F Y, H:i', (int) $timestamp );
    return is_string( $when ) ? $when : '';
}

// The result of the most recent test email (an array), or an empty array if there hasn't been one.
function webcare_email_get_last_test() {
    $saved = get_option( 'webcare_email_last_test', [] );
    if ( ! is_array( $saved ) || empty( $saved['time'] ) ) {
        return [];
    }
    return [
        'time'   => (int) $saved['time'],
        'ok'     => ! empty( $saved['ok'] ),
        'reason' => isset( $saved['reason'] ) && is_string( $saved['reason'] ) ? $saved['reason'] : '',
        'from'   => isset( $saved['from'] ) && is_string( $saved['from'] ) ? $saved['from'] : '',
        'mailer' => isset( $saved['mailer'] ) && is_string( $saved['mailer'] ) ? $saved['mailer'] : '',
        'host'   => isset( $saved['host'] ) && is_string( $saved['host'] ) ? $saved['host'] : '',
    ];
}

// The address WordPress sends from when nothing else is set (same rule as WordPress's own wp_mail()),
// after any filters. An email plugin may change this. Returns '' if it isn't a valid address.
function webcare_email_default_from() {
    $host = wp_parse_url( network_home_url(), PHP_URL_HOST );
    $from = 'wordpress@';
    if ( is_string( $host ) && '' !== $host ) {
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        $from .= $host;
    }
    $from = apply_filters( 'wp_mail_from', $from );
    return ( is_string( $from ) && is_email( $from ) ) ? $from : '';
}

// The domain whose records we check: from the last test's From address, else the default From address.
// Returns '' if it doesn't look like a real domain name.
function webcare_email_check_domain() {
    $last = webcare_email_get_last_test();
    $from = ( ! empty( $last['from'] ) ) ? $last['from'] : webcare_email_default_from();
    $pos  = strrpos( $from, '@' );
    if ( false === $pos ) {
        return '';
    }
    $domain = strtolower( trim( substr( $from, $pos + 1 ) ) );
    if ( 1 !== preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
        return '';
    }
    return $domain;
}

function webcare_email_dns_transient_key( $domain ) {
    return 'webcare_mail_dns_' . md5( $domain );
}

// Which email-sending plugin is active, in plain words ('' if none we recognise).
function webcare_email_detect_plugin() {
    if ( defined( 'FLUENTMAIL' ) ) {
        return 'FluentSMTP';
    }
    if ( defined( 'WPMS_PLUGIN_VER' ) ) {
        return 'WP Mail SMTP';
    }
    if ( defined( 'POST_SMTP_VER' ) ) {
        return 'Post SMTP';
    }
    if ( class_exists( 'EasyWPSMTP\\Core' ) || class_exists( 'EasyWPSMTP' ) || defined( 'EASY_WP_SMTP_PLUGIN_VERSION' ) ) {
        return 'Easy WP SMTP';
    }
    if ( defined( 'SMTP_MAILER_VERSION' ) ) {
        return 'SMTP Mailer';
    }
    if ( class_exists( 'SIB_Manager' ) ) {
        return 'Brevo (Sendinblue)';
    }
    if ( class_exists( 'SendGrid_Tools' ) ) {
        return 'SendGrid';
    }
    return '';
}

// How the last test was sent, in plain English.
function webcare_email_mailer_text( $mailer, $host, $plugin = '' ) {
    switch ( $mailer ) {
        case 'smtp':
            return ( '' !== $host )
                /* translators: %s: mail server name */
                ? sprintf( __( 'SMTP via %s', 'webcare' ), $host )
                : __( 'SMTP', 'webcare' );
        case 'mail':
            return __( 'The server\'s basic mail function', 'webcare' );
        case 'sendmail':
            return __( 'The server\'s "sendmail" program', 'webcare' );
        case 'qmail':
            return __( 'The server\'s "qmail" program', 'webcare' );
        case 'plugin':
            // Some plugins (FluentSMTP with an API connection, for example) skip the usual mail step.
            return ( '' !== $plugin )
                /* translators: %s: email plugin name, e.g. FluentSMTP */
                ? sprintf( __( 'Through %s\'s own connection', 'webcare' ), $plugin )
                : __( 'An email plugin\'s own connection', 'webcare' );
    }
    return __( 'Unknown', 'webcare' );
}

/* ------------------------------------------------------------------
 * B. Failed email counter (runs on wp_mail_failed, for ANY email on the site)
 * ---------------------------------------------------------------- */

// Must be cheap and must never break the email that is failing, so everything is wrapped.
function webcare_record_mail_failure( $error = null ) {
    try {
        $reason = '';
        if ( is_wp_error( $error ) ) {
            $reason = webcare_redact_mail_error( $error->get_error_message() );
        } elseif ( is_string( $error ) ) {
            $reason = webcare_redact_mail_error( $error );
        }

        $data = get_option( 'webcare_mail_failures', [] );
        if ( ! is_array( $data ) ) {
            $data = [];
        }
        $months = ( isset( $data['months'] ) && is_array( $data['months'] ) ) ? $data['months'] : [];

        $key            = wp_date( 'Y_m' );
        $months[ $key ] = ( isset( $months[ $key ] ) ? (int) $months[ $key ] : 0 ) + 1;
        krsort( $months );
        $months = array_slice( $months, 0, 3, true ); // Keep only the latest three months.

        update_option(
            'webcare_mail_failures',
            [
                'months'      => $months,
                'last_time'   => time(),
                'last_reason' => $reason,
            ],
            false
        );
    } catch ( \Throwable $e ) {
        // Deliberately silent: counting must never get in the way of anything else.
        return;
    }
}

/* ------------------------------------------------------------------
 * A. Test email handler (admin_post_webcare_email_test - logged-in users only)
 * ---------------------------------------------------------------- */

function webcare_handle_email_test() {
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        wp_die( 'Unauthorised', 403 );
    }
    check_admin_referer( 'webcare_email_test' );

    // Editors: the test only ever goes to their own account address.
    // Administrators may type a different address (e.g. when the login uses a placeholder address).
    $user      = wp_get_current_user();
    $recipient = $user->user_email;
    if ( current_user_can( 'manage_options' ) && isset( $_POST['webcare_test_to'] ) ) {
        $typed = sanitize_email( wp_unslash( (string) $_POST['webcare_test_to'] ) );
        if ( '' !== trim( (string) wp_unslash( $_POST['webcare_test_to'] ) ) ) {
            if ( ! is_email( $typed ) ) {
                webcare_redirect( 'email_test_bad_address' );
            }
            $recipient = $typed;
        }
    }
    if ( ! is_email( $recipient ) ) {
        webcare_redirect( 'email_test_no_address' );
    }

    // One test per user per minute.
    $rate_key = 'webcare_mailtest_' . $user->ID;
    if ( false !== get_transient( $rate_key ) ) {
        webcare_redirect( 'email_test_rate_limited' );
    }
    // Start the wait BEFORE sending, so a quick double-click can't send twice.
    set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

    $domain = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( ! $domain ) {
        $domain = get_bloginfo( 'name' );
    }
    $domain  = webcare_strip_newlines( (string) $domain );
    $subject = webcare_strip_newlines( '[Webcare] Test email from ' . $domain );

    $body  = "This is a test email from the Webcare email check.\n\n";
    $body .= 'Site: ' . get_bloginfo( 'name' ) . "\n";
    $body .= 'Site URL: ' . home_url() . "\n";
    $body .= 'Sent: ' . wp_date( 'j F Y, H:i' ) . "\n\n";
    $body .= "If you got this, your website can send emails. No need to reply.\n";

    // We deliberately don't set From: - keeping the site default protects email delivery (SPF/DKIM).
    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

    // While sending, listen in (temporarily) to learn what went wrong, or how the email was sent.
    $error_message = '';
    $details       = null;

    $on_failed = function ( $error ) use ( &$error_message ) {
        if ( is_wp_error( $error ) ) {
            $error_message = (string) $error->get_error_message();
        }
    };
    $on_phpmailer = function ( $phpmailer ) use ( &$details ) {
        $details = [ 'from' => '', 'mailer' => '', 'host' => '' ];
        if ( is_object( $phpmailer ) ) {
            $details['from']   = isset( $phpmailer->From ) ? (string) $phpmailer->From : '';
            $details['mailer'] = isset( $phpmailer->Mailer ) ? (string) $phpmailer->Mailer : '';
            if ( 'smtp' === $details['mailer'] && isset( $phpmailer->Host ) ) {
                $details['host'] = (string) $phpmailer->Host;
            }
        }
    };
    add_action( 'wp_mail_failed', $on_failed );
    add_action( 'phpmailer_init', $on_phpmailer, PHP_INT_MAX );

    $sent = false;
    try {
        $sent = (bool) wp_mail( $recipient, $subject, $body, $headers );
    } catch ( \Throwable $e ) {
        $sent          = false;
        $error_message = $e->getMessage();
    } finally {
        remove_action( 'wp_mail_failed', $on_failed );
        remove_action( 'phpmailer_init', $on_phpmailer, PHP_INT_MAX );
    }

    // Work out what to remember about how it was sent.
    $from   = '';
    $mailer = '';
    $host   = '';
    if ( is_array( $details ) ) {
        $from = is_email( $details['from'] ) ? strtolower( $details['from'] ) : '';
        if ( in_array( $details['mailer'], [ 'smtp', 'mail', 'sendmail', 'qmail' ], true ) ) {
            $mailer = $details['mailer'];
        }
        $host = sanitize_text_field( $details['host'] );
        $host = function_exists( 'mb_substr' ) ? mb_substr( $host, 0, 200, 'UTF-8' ) : substr( $host, 0, 200 );
    } elseif ( $sent ) {
        // Sent, but PHPMailer never ran: an email plugin used its own connection (for example an API).
        $mailer = 'plugin';
    }

    // The recipient address is deliberately not stored.
    $result = [
        'time'   => time(),
        'ok'     => $sent,
        'reason' => $sent ? '' : webcare_redact_mail_error( $error_message ),
        'from'   => $from,
        'mailer' => $mailer,
        'host'   => $host,
    ];
    if ( false === get_option( 'webcare_email_last_test', false ) ) {
        add_option( 'webcare_email_last_test', $result, '', 'no' );
    } else {
        update_option( 'webcare_email_last_test', $result, false );
    }

    if ( ! $sent ) {
        // Lift the wait so they can try again straight away.
        delete_transient( $rate_key );
        error_log( '[Webcare] Test email failed for ' . $domain . '.' );
        webcare_redirect( 'email_test_failed' );
    }

    // The sending details may have changed, so let the domain records be looked up afresh.
    $check_domain = webcare_email_check_domain();
    if ( '' !== $check_domain ) {
        delete_transient( webcare_email_dns_transient_key( $check_domain ) );
    }

    webcare_redirect( 'email_test_sent' );
}

/* ------------------------------------------------------------------
 * D. Domain email records (SPF / DMARC) lookup
 * ---------------------------------------------------------------- */

// All TXT record texts for a host, or false if the lookup could not be done.
function webcare_email_txt_records( $host ) {
    if ( ! function_exists( 'dns_get_record' ) ) {
        return false;
    }
    try {
        // dns_get_record() prints a PHP warning when a lookup fails, so the warning is silenced.
        $records = @dns_get_record( $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    } catch ( \Throwable $e ) {
        return false;
    }
    if ( ! is_array( $records ) ) {
        return false;
    }
    $texts = [];
    foreach ( $records as $record ) {
        if ( ! is_array( $record ) ) {
            continue;
        }
        if ( isset( $record['txt'] ) && is_string( $record['txt'] ) ) {
            $texts[] = $record['txt'];
        } elseif ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
            $texts[] = implode( '', array_filter( $record['entries'], 'is_string' ) );
        }
    }
    return $texts;
}

// SPF and DMARC findings for a domain, kept for 12 hours so the page stays fast.
function webcare_email_dns_check( $domain ) {
    $key    = webcare_email_dns_transient_key( $domain );
    $cached = get_transient( $key );
    if ( is_array( $cached ) && isset( $cached['checked'], $cached['spf'], $cached['dmarc'] ) ) {
        return $cached;
    }

    $result = [
        'checked'  => time(),
        'spf'      => 'unknown', // found / missing / unknown
        'spf_text' => '',
        'dmarc'    => 'unknown',
        'policy'   => '',
    ];

    $txt = webcare_email_txt_records( $domain );
    if ( is_array( $txt ) ) {
        $result['spf'] = 'missing';
        foreach ( $txt as $text ) {
            if ( 0 === stripos( ltrim( $text ), 'v=spf1' ) ) {
                $result['spf']      = 'found';
                $result['spf_text'] = webcare_redact_mail_error( $text );
                break;
            }
        }
    }

    $dmarc_txt = webcare_email_txt_records( '_dmarc.' . $domain );
    if ( ! is_array( $dmarc_txt ) && is_array( $txt ) ) {
        // The domain itself answered but the "_dmarc" name did not exist, which is how a missing record usually looks.
        $dmarc_txt = [];
    }
    if ( is_array( $dmarc_txt ) ) {
        $result['dmarc'] = 'missing';
        foreach ( $dmarc_txt as $text ) {
            if ( 0 === stripos( ltrim( $text ), 'v=dmarc1' ) ) {
                $result['dmarc'] = 'found';
                if ( 1 === preg_match( '/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\b/i', $text, $match ) ) {
                    $result['policy'] = strtolower( $match[1] );
                }
                break;
            }
        }
    }

    // If neither lookup worked, try again in an hour rather than 12 (not sooner: a slow or broken
    // lookup service can hold up the page each time it is retried).
    $lifetime = ( 'unknown' === $result['spf'] && 'unknown' === $result['dmarc'] ) ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS;
    set_transient( $key, $result, $lifetime );

    return $result;
}

/* ------------------------------------------------------------------
 * The card on the Webcare page
 * ---------------------------------------------------------------- */

function webcare_render_email_check_section() {
    $user = wp_get_current_user();
    $last = webcare_email_get_last_test();

    // --- A. Test email ---
    $can_choose = current_user_can( 'manage_options' );
    // Don't pre-fill placeholder login addresses (e.g. wp@wordpress.com) - nobody reads those.
    $prefill = is_email( $user->user_email ) ? $user->user_email : '';
    if ( preg_match( '/@(wordpress\.com|example\.(com|org|net))$/i', $prefill ) ) {
        $prefill = '';
    }
    if ( $can_choose ) {
        echo '<p>' . esc_html__( 'This sends a test email to check that your website can send emails, such as contact form messages.', 'webcare' ) . '</p>';
    } else {
        echo '<p>' . esc_html__( 'This sends a test email to your own account address to check that your website can send emails, such as contact form messages.', 'webcare' ) . '</p>';
        if ( is_email( $user->user_email ) ) {
            echo '<p>' . esc_html__( 'The test will be sent to:', 'webcare' ) . ' <strong>' . esc_html( $user->user_email ) . '</strong></p>';
        }
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="webcare_email_test">
        <?php wp_nonce_field( 'webcare_email_test' ); ?>
        <?php if ( $can_choose ) : ?>
            <p>
                <label for="webcare-test-to"><?php echo esc_html__( 'Send the test to:', 'webcare' ); ?></label><br>
                <input type="email" id="webcare-test-to" name="webcare_test_to" class="regular-text" value="<?php echo esc_attr( $prefill ); ?>">
            </p>
        <?php endif; ?>
        <p><button type="submit" class="button"><?php echo esc_html__( 'Send a test email', 'webcare' ); ?></button></p>
    </form>
    <p class="webcare-help"><?php echo esc_html__( '"Sent" means your website handed the email over successfully. The only way to be sure it arrived is to check your inbox.', 'webcare' ); ?></p>
    <?php
    if ( ! empty( $last ) ) {
        echo '<p>' . esc_html(
            sprintf(
                /* translators: 1: date and time, 2: "sent" or "could not be sent" */
                __( 'Last test: %1$s — %2$s', 'webcare' ),
                webcare_email_time_text( $last['time'] ),
                $last['ok'] ? __( 'sent', 'webcare' ) : __( 'could not be sent', 'webcare' )
            )
        ) . '</p>';
    }

    // --- B. Failed email counter ---
    echo '<h3>' . esc_html__( 'Failed emails', 'webcare' ) . '</h3>';
    $data   = get_option( 'webcare_mail_failures', [] );
    $data   = is_array( $data ) ? $data : [];
    $months = ( isset( $data['months'] ) && is_array( $data['months'] ) ) ? $data['months'] : [];

    $this_key = wp_date( 'Y_m' );
    $last_key = gmdate( 'Y_m', gmmktime( 12, 0, 0, (int) wp_date( 'n' ) - 1, 15, (int) wp_date( 'Y' ) ) );
    $this_n   = isset( $months[ $this_key ] ) ? (int) $months[ $this_key ] : 0;
    $last_n   = isset( $months[ $last_key ] ) ? (int) $months[ $last_key ] : 0;
    $has_any  = ( ! empty( $data['last_time'] ) );

    if ( $has_any ) {
        echo '<p>' . esc_html(
            sprintf(
                /* translators: 1: number this month, 2: number last month */
                __( 'Emails that failed to send — this month: %1$s, last month: %2$s', 'webcare' ),
                number_format_i18n( $this_n ),
                number_format_i18n( $last_n )
            )
        ) . '</p>';
        $when = webcare_email_time_text( $data['last_time'] );
        if ( '' !== $when ) {
            echo '<p>' . esc_html( sprintf( /* translators: %s: date and time */ __( 'Last failure: %s', 'webcare' ), $when ) ) . '</p>';
        }
        if ( current_user_can( 'manage_options' ) && ! empty( $data['last_reason'] ) && is_string( $data['last_reason'] ) ) {
            echo '<p>' . esc_html__( 'Reason given:', 'webcare' ) . ' <code>' . esc_html( $data['last_reason'] ) . '</code></p>';
        }
    } else {
        echo '<p>' . esc_html__( 'No failed emails recorded.', 'webcare' ) . '</p>';
    }
    echo '<p class="webcare-help">' . esc_html__( 'This counts every email your website tried and failed to send, including contact form messages.', 'webcare' ) . '</p>';

    // --- C and D. Administrators only ---
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<h3>' . esc_html__( 'Sending setup (Administrators only)', 'webcare' ) . '</h3>';
    echo '<ul class="webcare-list">';

    $plugin = webcare_email_detect_plugin();
    if ( '' !== $plugin ) {
        $plugin_text = $plugin;
    } elseif ( has_action( 'phpmailer_init' ) || has_filter( 'pre_wp_mail' ) ) {
        $plugin_text = __( 'Something on this site customises how email is sent (unknown plugin)', 'webcare' );
    } else {
        $plugin_text = __( 'None — emails use the server\'s basic mail function, which is more likely to land in spam. APM recommends an email-sending plugin (e.g. FluentSMTP).', 'webcare' );
    }
    echo '<li>' . esc_html__( 'Email plugin:', 'webcare' ) . ' ' . esc_html( $plugin_text ) . '</li>';
    if ( 'FluentSMTP' === $plugin && current_user_can( 'manage_options' ) ) {
        echo '<li><a href="' . esc_url( admin_url( 'options-general.php?page=fluent-mail#/logs' ) ) . '">'
            . esc_html__( 'FluentSMTP keeps its own log of every email sent — open FluentSMTP\'s email log', 'webcare' )
            . '</a></li>';
    }

    if ( ! empty( $last ) ) {
        if ( '' !== $last['from'] ) {
            echo '<li>' . esc_html__( 'Sent from address:', 'webcare' ) . ' <code>' . esc_html( $last['from'] ) . '</code></li>';
        }
        echo '<li>' . esc_html__( 'How the last test was sent:', 'webcare' ) . ' ' . esc_html( webcare_email_mailer_text( $last['mailer'], $last['host'], $plugin ) ) . '</li>';
        if ( ! $last['ok'] && '' !== $last['reason'] ) {
            echo '<li>' . esc_html__( 'Reason given:', 'webcare' ) . ' <code>' . esc_html( $last['reason'] ) . '</code></li>';
        }
    } else {
        echo '<li>' . esc_html__( 'Send a test email to see the sending details.', 'webcare' ) . '</li>';
        $default_from = webcare_email_default_from();
        if ( '' !== $default_from ) {
            echo '<li>' . esc_html__( 'Default From address (an email plugin may change this):', 'webcare' ) . ' <code>' . esc_html( $default_from ) . '</code></li>';
        }
    }
    echo '</ul>';

    echo '<h3>' . esc_html__( 'Domain email records (Administrators only)', 'webcare' ) . '</h3>';
    $domain = webcare_email_check_domain();
    if ( '' === $domain ) {
        echo '<p>' . esc_html__( 'The sending domain could not be worked out, so the records could not be checked.', 'webcare' ) . '</p>';
        return;
    }

    $dns = webcare_email_dns_check( $domain );

    if ( 'found' === $dns['spf'] ) {
        $spf_text = __( 'Found', 'webcare' );
    } elseif ( 'missing' === $dns['spf'] ) {
        $spf_text = __( 'Not found — emails may be marked as spam', 'webcare' );
    } else {
        $spf_text = __( 'Couldn\'t check', 'webcare' );
    }

    if ( 'found' === $dns['dmarc'] ) {
        $dmarc_text = ( '' !== $dns['policy'] )
            /* translators: %s: DMARC policy: none, quarantine or reject */
            ? sprintf( __( 'Found (policy: %s)', 'webcare' ), $dns['policy'] )
            : __( 'Found', 'webcare' );
    } elseif ( 'missing' === $dns['dmarc'] ) {
        $dmarc_text = __( 'Not found (this can occasionally be a temporary lookup problem; it is checked again within 12 hours)', 'webcare' );
    } else {
        $dmarc_text = __( 'Couldn\'t check', 'webcare' );
    }

    echo '<p>' . esc_html__( 'Domain checked:', 'webcare' ) . ' <code>' . esc_html( $domain ) . '</code></p>';
    echo '<ul class="webcare-list">';
    echo '<li>' . esc_html__( 'SPF record:', 'webcare' ) . ' ' . esc_html( $spf_text );
    if ( '' !== $dns['spf_text'] ) {
        echo '<br><code>' . esc_html( $dns['spf_text'] ) . '</code>';
    }
    echo '</li>';
    echo '<li>' . esc_html__( 'DMARC record:', 'webcare' ) . ' ' . esc_html( $dmarc_text ) . '</li>';
    echo '</ul>';

    $checked = webcare_email_time_text( $dns['checked'] );
    if ( '' !== $checked ) {
        echo '<p class="webcare-help">' . esc_html( sprintf( /* translators: %s: date and time */ __( 'Checked %s', 'webcare' ), $checked ) ) . '</p>';
    }
    echo '<p class="webcare-help">' . esc_html__( 'Found doesn\'t guarantee the record includes your sending service. DKIM (the third record) can\'t be checked from here — ask APM if emails land in spam.', 'webcare' ) . '</p>';
}

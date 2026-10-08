<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------
 * Menu entry ("Business details" under Webcare)
 * ---------------------------------------------------------------- */

// Remembers the page's screen id so the stylesheet can be loaded on it.
// Call with no argument to read it, or with a value to store it.
function webcare_business_hook_suffix( $set = null ) {
    static $suffix = '';
    if ( null !== $set ) {
        $suffix = (string) $set;
    }
    return $suffix;
}

function webcare_register_business_menu() {
    // First re-add the main page as "Overview", so WordPress doesn't show a second
    // "Webcare" entry in the submenu. Same slug and same page as the top-level menu.
    add_submenu_page(
        'webcare',
        __( 'Webcare', 'webcare' ),
        __( 'Overview', 'webcare' ),
        WEBCARE_CAPABILITY,
        'webcare',
        'webcare_render_page'
    );

    $hook = add_submenu_page(
        'webcare',
        __( 'Business details for Google & AI', 'webcare' ),
        __( 'Business details', 'webcare' ),
        WEBCARE_CAPABILITY,
        'webcare-business',
        'webcare_render_business_page'
    );
    if ( is_string( $hook ) ) {
        webcare_business_hook_suffix( $hook );
    }
}

/* ------------------------------------------------------------------
 * Cleaning what was typed
 * ---------------------------------------------------------------- */

// Checks one opening/closing pair. Returns [ opens, closes, ok ].
// Both empty is fine (nothing entered); a half-filled or backwards pair is "not ok".
function webcare_biz_clean_session( $opens, $closes ) {
    $opens  = is_string( $opens ) ? trim( $opens ) : '';
    $closes = is_string( $closes ) ? trim( $closes ) : '';
    if ( '' === $opens && '' === $closes ) {
        return [ '', '', true ];
    }
    if ( webcare_is_valid_time( $opens ) && webcare_is_valid_time( $closes ) && $closes > $opens ) {
        return [ $opens, $closes, true ];
    }
    return [ '', '', false ];
}

// Turns the raw (already unslashed) form values into the array we store.
// Returns [ details, valid, hours_dropped, blank_days, booking_dropped ].
//   valid         - false if something needs the user to fix it (bad type or email).
//   hours_dropped - true if some opening times were invalid and left out.
//   blank_days    - true if some days are neither ticked Closed nor filled in (while others are).
//   booking_dropped - true if an "Online booking link" was typed but could not be used.
function webcare_sanitize_business_details( $raw ) {
    $raw           = is_array( $raw ) ? $raw : [];
    $valid         = true;
    $hours_dropped = false;
    $details       = webcare_business_defaults();

    $get = function ( $key ) use ( $raw ) {
        return ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) ? $raw[ $key ] : '';
    };
    $plain = function ( $key, $max ) use ( $get ) {
        return webcare_substr( trim( webcare_strip_newlines( sanitize_text_field( $get( $key ) ) ) ), $max );
    };

    // Business type: only our own list is accepted.
    $type  = $plain( 'type', 50 );
    $types = webcare_business_types();
    if ( isset( $types[ $type ] ) ) {
        $details['type'] = $type;
    } else {
        $valid = false;
    }

    $details['name']        = $plain( 'name', 200 );
    $details['description'] = $plain( 'description', 300 );

    // Phone: keep only characters that belong in a phone number.
    $phone = preg_replace( '/[^0-9+()\-. ]/', '', $plain( 'phone', 40 ) );
    $details['phone'] = ( null === $phone ) ? '' : trim( $phone );

    // Email: must be a real address if one is given.
    $email_typed = $plain( 'email', 200 );
    if ( '' !== $email_typed ) {
        $email = sanitize_email( $email_typed );
        if ( '' === $email || ! is_email( $email ) ) {
            $valid = false;
            $email = $email_typed; // Shown back to the user so they can fix it.
        }
        $details['email'] = $email;
    }

    $details['street']   = $plain( 'street', 200 );
    $details['city']     = $plain( 'city', 100 );
    $details['county']   = $plain( 'county', 100 );
    $details['postcode'] = $plain( 'postcode', 20 );
    $details['country']  = $plain( 'country', 60 );
    $details['price']    = $plain( 'price', 20 );

    // Online booking link (optional). Used only by the enquiry-action counter, never published
    // in the schema markup. Must be a real http/https address that is not just our own home page.
    $booking_dropped = false;
    $booking_typed   = $plain( 'booking_url', 300 );
    $details['booking_url'] = '';
    if ( '' !== $booking_typed ) {
        $booking = esc_url_raw( $booking_typed, [ 'http', 'https' ] );
        if ( '' !== $booking && '' !== webcare_booking_rule_from_url( $booking ) ) {
            $details['booking_url'] = $booking;
        } else {
            $booking_dropped = true;
        }
    }

    // Services: one per line, up to 20 lines, each up to 100 characters.
    $services = [];
    foreach ( explode( "\n", webcare_clean_text( webcare_substr( $get( 'services' ), 5000 ), true ) ) as $line ) {
        $line = webcare_substr( trim( sanitize_text_field( $line ) ), 100 );
        if ( '' !== $line ) {
            $services[] = $line;
        }
        if ( count( $services ) >= 20 ) {
            break;
        }
    }
    $details['services'] = $services;

    // Areas served: comma-separated, up to 20, each up to 60 characters.
    $areas = [];
    foreach ( explode( ',', webcare_substr( $get( 'areas' ), 1000 ) ) as $area ) {
        $area = webcare_substr( trim( sanitize_text_field( $area ) ), 60 );
        if ( '' !== $area ) {
            $areas[] = $area;
        }
        if ( count( $areas ) >= 20 ) {
            break;
        }
    }
    $details['areas'] = $areas;

    // Opening hours.
    $raw_hours = ( isset( $raw['hours'] ) && is_array( $raw['hours'] ) ) ? $raw['hours'] : [];
    $hours     = [];
    foreach ( webcare_business_days() as $day_key => $day ) {
        $in = ( isset( $raw_hours[ $day_key ] ) && is_array( $raw_hours[ $day_key ] ) ) ? $raw_hours[ $day_key ] : [];
        $pick = function ( $field ) use ( $in ) {
            return ( isset( $in[ $field ] ) && is_string( $in[ $field ] ) ) ? $in[ $field ] : '';
        };

        $row = [
            'closed' => ! empty( $in['closed'] ),
            'open1'  => '',
            'close1' => '',
            'open2'  => '',
            'close2' => '',
        ];

        if ( ! $row['closed'] ) {
            $first = webcare_biz_clean_session( $pick( 'open1' ), $pick( 'close1' ) );
            if ( ! $first[2] ) {
                $hours_dropped = true;
            }
            $row['open1']  = $first[0];
            $row['close1'] = $first[1];

            $second = webcare_biz_clean_session( $pick( 'open2' ), $pick( 'close2' ) );
            // The second session only counts if there is a first one and it starts after the first ends.
            if ( '' !== $second[0] && ( '' === $row['close1'] || $second[0] < $row['close1'] ) ) {
                $second = [ '', '', false ];
            }
            if ( ! $second[2] ) {
                $hours_dropped = true;
            }
            $row['open2']  = $second[0];
            $row['close2'] = $second[1];
        }

        $hours[ $day_key ] = $row;
    }
    $details['hours']   = $hours;
    $details['version'] = 1;

    // Blank days: if at least one day has hours, any day that is neither "Closed" nor filled in
    // will be treated by Google as closed. We still save, but the user is warned.
    $any_hours  = false;
    $blank_days = false;
    foreach ( $hours as $row ) {
        if ( ! $row['closed'] && '' !== $row['open1'] ) {
            $any_hours = true;
        }
    }
    if ( $any_hours ) {
        foreach ( $hours as $row ) {
            if ( ! $row['closed'] && '' === $row['open1'] ) {
                $blank_days = true;
            }
        }
    }

    return [ $details, $valid, $hours_dropped, $blank_days, $booking_dropped ];
}

/* ------------------------------------------------------------------
 * Form handler (admin_post_webcare_business - logged-in users only)
 * ---------------------------------------------------------------- */

// Where we park what the user typed if something needs fixing (5 minutes, this user only).
function webcare_business_transient_key( $user_id ) {
    return 'webcare_biz_form_' . (int) $user_id;
}

function webcare_handle_business() {
    // Checked on every request, not just when the menu is shown.
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        wp_die( 'Unauthorised', 403 );
    }
    check_admin_referer( 'webcare_business' );

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
    $raw = ( isset( $_POST['webcare_biz'] ) && is_array( $_POST['webcare_biz'] ) ) ? wp_unslash( $_POST['webcare_biz'] ) : [];
    // phpcs:enable

    $result        = webcare_sanitize_business_details( $raw );
    $details       = $result[0];
    $valid         = $result[1];
    $hours_dropped = $result[2];
    $blank_days    = $result[3];
    $booking_drop  = $result[4];

    if ( ! $valid ) {
        set_transient( webcare_business_transient_key( get_current_user_id() ), $details, 5 * MINUTE_IN_SECONDS );
        webcare_redirect( 'business_invalid', 'webcare-business' );
    }

    // One option holds everything. (update_option returns false when nothing changed; that is fine.)
    // We deliberately do not clear any caches here - the markup refreshes as pages are re-cached.
    update_option( 'webcare_business_details', $details, true );
    delete_transient( webcare_business_transient_key( get_current_user_id() ) );

    // The most important warning wins: invalid times first, then the booking link, then blank days.
    $msg = 'business_saved';
    if ( $hours_dropped ) {
        $msg = 'business_saved_hours';
    } elseif ( $booking_drop ) {
        $msg = 'business_saved_booking';
    } elseif ( $blank_days ) {
        $msg = 'business_saved_blank_days';
    }
    webcare_redirect( $msg, 'webcare-business' );
}

/* ------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------- */

// One simple text-style field with label, optional help text and optional placeholder.
function webcare_render_business_field( $key, $label, $value, $args = [] ) {
    $args = array_merge(
        [
            'type'         => 'text',
            'maxlength'    => 200,
            'placeholder'  => '',
            'help'         => '',
            'autocomplete' => '',
        ],
        $args
    );
    $id = 'webcare-biz-' . $key;
    echo '<div class="webcare-field">';
    echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
    printf(
        '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" maxlength="%5$d"%6$s%7$s>',
        esc_attr( $args['type'] ),
        esc_attr( $id ),
        esc_attr( 'webcare_biz[' . $key . ']' ),
        esc_attr( $value ),
        (int) $args['maxlength'],
        '' !== $args['placeholder'] ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : '',
        '' !== $args['autocomplete'] ? ' autocomplete="' . esc_attr( $args['autocomplete'] ) . '"' : ''
    );
    if ( '' !== $args['help'] ) {
        echo '<span class="webcare-help">' . esc_html( $args['help'] ) . '</span>';
    }
    echo '</div>';
}

// One time box in the opening-hours grid.
function webcare_render_hours_time( $day_key, $field, $value, $label ) {
    $id = 'webcare-hours-' . $day_key . '-' . $field;
    echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
    printf(
        '<input type="time" id="%1$s" name="%2$s" value="%3$s">',
        esc_attr( $id ),
        esc_attr( 'webcare_biz[hours][' . $day_key . '][' . $field . ']' ),
        esc_attr( $value )
    );
}

function webcare_render_business_page() {
    if ( ! current_user_can( WEBCARE_CAPABILITY ) ) {
        wp_die( esc_html__( 'Unauthorised', 'webcare' ), 403 );
    }

    $values = webcare_get_business_details();

    // If the last save needed fixing, show what they typed rather than the old saved version.
    $transient_key = webcare_business_transient_key( get_current_user_id() );
    $typed         = get_transient( $transient_key );
    if ( is_array( $typed ) ) {
        $values = array_merge( $values, $typed );
        delete_transient( $transient_key );
    }

    $status = webcare_business_status();
    $text   = function ( $key ) use ( $values ) {
        return webcare_biz_text( $values, $key );
    };
    ?>
    <div class="wrap webcare-wrap">
        <h1><?php echo esc_html__( 'Business details for Google & AI', 'webcare' ); ?></h1>
        <?php webcare_render_notice(); ?>

        <div class="webcare-card">
            <h2><?php echo esc_html__( 'Help Google and AI find you', 'webcare' ); ?></h2>
            <p><?php echo esc_html__( 'These details are published invisibly in your website\'s code, so Google and AI assistants (ChatGPT, Claude, Google\'s AI answers) can read your phone number, address, opening hours and services accurately.', 'webcare' ); ?></p>
            <p><?php echo esc_html__( 'They don\'t change what visitors see. If your opening hours are shown on a page, that text still needs updating separately — use "Request a change" on the Webcare page.', 'webcare' ); ?></p>
            <p><?php echo esc_html__( 'After you save, changes can take a few minutes to appear because of page caching.', 'webcare' ); ?></p>
            <div class="notice notice-<?php echo esc_attr( $status[0] ); ?> inline"><p><strong><?php echo esc_html__( 'Status:', 'webcare' ); ?></strong> <?php echo esc_html( $status[1] ); ?></p></div>
        </div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="webcare_business">
            <?php wp_nonce_field( 'webcare_business' ); ?>

            <div class="webcare-card">
                <h2><?php echo esc_html__( 'About your business', 'webcare' ); ?></h2>

                <div class="webcare-field">
                    <label for="webcare-biz-type"><?php echo esc_html__( 'Type of business', 'webcare' ); ?></label>
                    <select id="webcare-biz-type" name="webcare_biz[type]">
                        <?php foreach ( webcare_business_types() as $type_key => $type_label ) : ?>
                            <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $text( 'type' ), $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                webcare_render_business_field(
                    'name',
                    __( 'Business name', 'webcare' ),
                    $text( 'name' ),
                    [
                        'placeholder' => get_bloginfo( 'name' ),
                        'help'        => __( 'Please type it in full — this must be filled in for your details to be published. This replaces the organisation name set in Yoast for Google and AI; your Yoast name is kept as an alternative name.', 'webcare' ),
                    ]
                );
                ?>

                <div class="webcare-field">
                    <label for="webcare-biz-description"><?php echo esc_html__( 'Short description', 'webcare' ); ?></label>
                    <textarea id="webcare-biz-description" name="webcare_biz[description]" rows="3" maxlength="300"><?php echo esc_textarea( $text( 'description' ) ); ?></textarea>
                    <span class="webcare-help"><?php echo esc_html__( 'One or two sentences about what you do (up to 300 characters).', 'webcare' ); ?></span>
                </div>

                <?php
                webcare_render_business_field( 'phone', __( 'Phone', 'webcare' ), $text( 'phone' ), [ 'type' => 'tel', 'maxlength' => 40, 'autocomplete' => 'off' ] );
                webcare_render_business_field( 'email', __( 'Email', 'webcare' ), $text( 'email' ), [ 'type' => 'email', 'autocomplete' => 'off' ] );
                webcare_render_business_field( 'price', __( 'Price range (optional)', 'webcare' ), $text( 'price' ), [ 'maxlength' => 20, 'placeholder' => '££', 'help' => __( 'For example £, ££ or £££.', 'webcare' ) ] );
                webcare_render_business_field(
                    'booking_url',
                    __( 'Online booking link (optional)', 'webcare' ),
                    $text( 'booking_url' ),
                    [
                        'maxlength'   => 300,
                        'placeholder' => 'https://yourclinic.cliniko.com',
                        'help'        => __( 'If clients book online, paste the address of your booking page. Webcare counts clicks on it (and on common booking systems such as Cliniko, Jane, Acuity and Calendly) in your "Enquiry actions" figures. Visitors see no difference, and this is not published for Google.', 'webcare' ),
                    ]
                );
                ?>
            </div>

            <div class="webcare-card">
                <h2><?php echo esc_html__( 'Address', 'webcare' ); ?></h2>
                <?php
                webcare_render_business_field( 'street', __( 'Street address', 'webcare' ), $text( 'street' ) );
                webcare_render_business_field( 'city', __( 'Town / city', 'webcare' ), $text( 'city' ), [ 'maxlength' => 100 ] );
                webcare_render_business_field( 'county', __( 'County', 'webcare' ), $text( 'county' ), [ 'maxlength' => 100 ] );
                webcare_render_business_field( 'postcode', __( 'Postcode', 'webcare' ), $text( 'postcode' ), [ 'maxlength' => 20 ] );
                webcare_render_business_field( 'country', __( 'Country', 'webcare' ), $text( 'country' ), [ 'maxlength' => 60, 'help' => __( 'Use GB for the United Kingdom.', 'webcare' ) ] );
                ?>
            </div>

            <div class="webcare-card">
                <h2><?php echo esc_html__( 'Opening hours', 'webcare' ); ?></h2>
                <p class="webcare-help"><?php echo esc_html__( 'Tick Closed for days you\'re shut. If you leave a day blank, Google and AI assistants will assume you\'re closed that day. If you close for lunch, use the second set of times for the afternoon.', 'webcare' ); ?></p>
                <table class="webcare-hours">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html__( 'Day', 'webcare' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Closed', 'webcare' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Opens', 'webcare' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Closes', 'webcare' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Opens again (optional)', 'webcare' ); ?></th>
                            <th scope="col"><?php echo esc_html__( 'Closes', 'webcare' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( webcare_business_days() as $day_key => $day ) : ?>
                            <?php $row = webcare_biz_day_hours( $values, $day_key ); ?>
                            <tr>
                                <th scope="row"><?php echo esc_html( $day['label'] ); ?></th>
                                <td>
                                    <label class="screen-reader-text" for="<?php echo esc_attr( 'webcare-hours-' . $day_key . '-closed' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: day name */ __( '%s: closed', 'webcare' ), $day['label'] ) ); ?></label>
                                    <input type="checkbox" id="<?php echo esc_attr( 'webcare-hours-' . $day_key . '-closed' ); ?>" name="<?php echo esc_attr( 'webcare_biz[hours][' . $day_key . '][closed]' ); ?>" value="1" <?php checked( $row['closed'] ); ?>>
                                </td>
                                <td><?php webcare_render_hours_time( $day_key, 'open1', $row['open1'], sprintf( /* translators: %s: day name */ __( '%s: opens', 'webcare' ), $day['label'] ) ); ?></td>
                                <td><?php webcare_render_hours_time( $day_key, 'close1', $row['close1'], sprintf( /* translators: %s: day name */ __( '%s: closes', 'webcare' ), $day['label'] ) ); ?></td>
                                <td><?php webcare_render_hours_time( $day_key, 'open2', $row['open2'], sprintf( /* translators: %s: day name */ __( '%s: opens again', 'webcare' ), $day['label'] ) ); ?></td>
                                <td><?php webcare_render_hours_time( $day_key, 'close2', $row['close2'], sprintf( /* translators: %s: day name */ __( '%s: closes again', 'webcare' ), $day['label'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="webcare-card">
                <h2><?php echo esc_html__( 'Services and areas', 'webcare' ); ?></h2>

                <div class="webcare-field">
                    <label for="webcare-biz-services"><?php echo esc_html__( 'Services you offer', 'webcare' ); ?></label>
                    <textarea id="webcare-biz-services" name="webcare_biz[services]" rows="6"><?php echo esc_textarea( implode( "\n", webcare_biz_list( $values, 'services' ) ) ); ?></textarea>
                    <span class="webcare-help"><?php echo esc_html__( 'One per line, up to 20 (for example: Osteopathy, Sports massage, Acupuncture).', 'webcare' ); ?></span>
                </div>

                <?php
                webcare_render_business_field(
                    'areas',
                    __( 'Areas you serve', 'webcare' ),
                    implode( ', ', webcare_biz_list( $values, 'areas' ) ),
                    [
                        'maxlength' => 1000,
                        'help'      => __( 'Towns and villages, separated by commas.', 'webcare' ),
                    ]
                );
                ?>
            </div>

            <p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save business details', 'webcare' ); ?></button></p>
        </form>
    </div>
    <?php
}

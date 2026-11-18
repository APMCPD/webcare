<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Google reviews: APM's health check app (Reach) PUSHES the business's latest Google reviews to this
 * website, and the [webcare_reviews] shortcode shows them on any page.
 *
 *   POST /wp-json/webcare/v1/reviews
 *
 * The website never contacts Google and holds no Google key. The request is signed with the same secret
 * "connection key" as the report feed, so only APM can send reviews. The full contract is in
 * docs/webcare-feed.md.
 *
 * What is shown is exactly what was sent, in the order sent. There is deliberately no option to hide
 * low ratings (hiding negative reviews is not allowed under UK consumer law). No review markup
 * (schema / JSON-LD) is added either: Google ignores a business's own reviews of itself.
 *
 * Every part is wrapped in try/catch so a problem here can never break the website.
 */

/* ------------------------------------------------------------------
 * On/off switch and fixed numbers
 * ---------------------------------------------------------------- */

// Can be switched off on one site (the address then does not exist):
//   add_filter( 'webcare_reviews_endpoint', '__return_false' );
function webcare_reviews_endpoint_enabled() {
    return (bool) apply_filters( 'webcare_reviews_endpoint', true );
}

// The biggest request we will read: 256 KB.
function webcare_reviews_max_body() {
    return 262144;
}

// The most reviews kept.
function webcare_reviews_max_kept() {
    return 50;
}

/* ------------------------------------------------------------------
 * Checking who is sending
 * ---------------------------------------------------------------- */

// The text that is signed: timestamp, "POST", the route, and the SHA-256 of the exact body, each on its
// own line (no newline at the very end).
function webcare_reviews_signing_string( $timestamp, $raw_body ) {
    return $timestamp . "\n" . 'POST' . "\n" . '/webcare/v1/reviews' . "\n" . hash( 'sha256', (string) $raw_body );
}

// The signature a correct sender would use: a lower-case hex HMAC-SHA256 of the signing string.
function webcare_reviews_signature( $key, $timestamp, $raw_body ) {
    return hash_hmac( 'sha256', webcare_reviews_signing_string( $timestamp, $raw_body ), $key );
}

// Is this a genuine, fresh, correctly signed request? Says only yes or no (never why not).
function webcare_reviews_request_is_signed( $request ) {
    $key = webcare_get_connection_key( false );
    if ( '' === $key ) {
        return false;
    }

    $timestamp = $request->get_header( 'x_webcare_timestamp' );
    $signature = $request->get_header( 'x_webcare_signature' );
    $body      = $request->get_body();

    if ( ! is_string( $timestamp ) || ! is_string( $signature ) || ! is_string( $body ) ) {
        return false;
    }
    if ( 1 !== preg_match( '/^[0-9]{1,12}\z/', $timestamp ) ) {
        return false;
    }
    if ( abs( time() - (int) $timestamp ) > webcare_feed_time_leeway() ) {
        return false;
    }
    if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}\z/', $signature ) ) {
        return false;
    }

    return hash_equals( webcare_reviews_signature( $key, $timestamp, $body ), strtolower( $signature ) );
}

// Runs before anything is read. Returns true, or an error that WordPress sends instead.
function webcare_reviews_permission( $request ) {
    try {
        if ( ! webcare_reviews_endpoint_enabled() ) {
            return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'webcare' ), [ 'status' => 404 ] );
        }
        if ( webcare_reviews_request_is_signed( $request ) ) {
            return true;
        }
        if ( webcare_feed_is_blocked() ) {
            return new WP_Error( 'webcare_rate_limited', __( 'Too many attempts. Please try again later.', 'webcare' ), [ 'status' => 429 ] );
        }
        webcare_feed_note_failure();
        return webcare_feed_unauthorised();
    } catch ( \Throwable $e ) {
        return webcare_feed_unauthorised();
    }
}

/* ------------------------------------------------------------------
 * Receiving reviews
 * ---------------------------------------------------------------- */

// Cuts text to a number of characters without breaking a multi-byte letter.
function webcare_reviews_truncate( $text, $max ) {
    $text = (string) $text;
    if ( function_exists( 'mb_substr' ) ) {
        return mb_substr( $text, 0, $max, 'UTF-8' );
    }
    return substr( $text, 0, $max );
}

// A whole number (a real integer, or a number written without decimals), or null.
function webcare_reviews_whole_number( $value ) {
    if ( is_int( $value ) ) {
        return $value;
    }
    if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 1e12 ) {
        return (int) $value;
    }
    return null;
}

// Cleans one review. Returns the clean review, or null if it is not usable (it is then left out).
function webcare_reviews_clean_review( $review ) {
    if ( ! is_array( $review ) ) {
        return null;
    }

    $rating = isset( $review['rating'] ) ? webcare_reviews_whole_number( $review['rating'] ) : null;
    if ( null === $rating || $rating < 1 || $rating > 5 ) {
        return null;
    }

    $date = isset( $review['date'] ) && is_string( $review['date'] ) ? $review['date'] : '';
    if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
        return null;
    }

    $id = '';
    if ( isset( $review['id'] ) && ( is_string( $review['id'] ) || is_int( $review['id'] ) ) ) {
        $id = webcare_reviews_truncate( sanitize_text_field( (string) $review['id'] ), 200 );
    }

    $name = '';
    if ( isset( $review['name'] ) && is_string( $review['name'] ) ) {
        $name = webcare_reviews_truncate( sanitize_text_field( wp_strip_all_tags( $review['name'] ) ), 60 );
    }
    if ( '' === $name ) {
        $name = __( 'A Google reviewer', 'webcare' );
    }

    $text = '';
    if ( isset( $review['text'] ) && is_string( $review['text'] ) ) {
        $text = webcare_reviews_truncate( sanitize_textarea_field( wp_strip_all_tags( $review['text'] ) ), 2000 );
    }

    return [
        'id'     => $id,
        'name'   => $name,
        'rating' => $rating,
        'text'   => $text,
        'date'   => $date,
    ];
}

function webcare_reviews_bad_request() {
    return new WP_Error( 'webcare_reviews_invalid', __( 'The reviews could not be read.', 'webcare' ), [ 'status' => 400 ] );
}

// Runs after the signature has been accepted: checks, cleans and saves what was sent.
function webcare_rest_reviews( $request ) {
    try {
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > webcare_reviews_max_body() ) {
            return webcare_reviews_bad_request();
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || ! isset( $data['reviews'] ) || ! is_array( $data['reviews'] ) ) {
            return webcare_reviews_bad_request();
        }
        if ( ! isset( $data['version'] ) || 1 !== webcare_reviews_whole_number( $data['version'] ) ) {
            return webcare_reviews_bad_request();
        }

        $generated_at = isset( $data['generated_at'] ) ? webcare_reviews_whole_number( $data['generated_at'] ) : null;
        // A time more than a day in the future is refused, so a bad clock can't lock out later updates.
        if ( null === $generated_at || $generated_at < 1 || $generated_at > time() + DAY_IN_SECONDS ) {
            return webcare_reviews_bad_request();
        }

        // A missing total is treated as 0 (the contract's own example body leaves it out).
        $total = array_key_exists( 'total_count', $data ) ? webcare_reviews_whole_number( $data['total_count'] ) : 0;
        if ( null === $total || $total < 0 ) {
            return webcare_reviews_bad_request();
        }

        $average = null;
        if ( isset( $data['average_rating'] ) && null !== $data['average_rating'] ) {
            if ( ! is_int( $data['average_rating'] ) && ! is_float( $data['average_rating'] ) ) {
                return webcare_reviews_bad_request();
            }
            $average = round( max( 0.0, min( 5.0, (float) $data['average_rating'] ) ), 2 );
        }

        // The link must be a plain https address, or it is left out.
        $place_url = '';
        if ( isset( $data['place_url'] ) && is_string( $data['place_url'] ) && '' !== trim( $data['place_url'] ) ) {
            $candidate = esc_url_raw( trim( $data['place_url'] ), [ 'https' ] );
            if ( '' !== $candidate && 0 === strpos( $candidate, 'https://' ) ) {
                $place_url = $candidate;
            }
        }

        // Never go backwards: an older set than the one we hold is refused (an equal one is fine).
        $stored = get_option( 'webcare_reviews', [] );
        if ( is_array( $stored ) && isset( $stored['generated_at'] ) && $generated_at < (int) $stored['generated_at'] ) {
            return new WP_Error( 'webcare_reviews_stale', __( 'These reviews are older than the ones already saved.', 'webcare' ), [ 'status' => 409 ] );
        }

        $clean = [];
        foreach ( array_values( $data['reviews'] ) as $review ) {
            $one = webcare_reviews_clean_review( $review );
            if ( null !== $one ) {
                $clean[] = $one;
            }
            if ( count( $clean ) >= webcare_reviews_max_kept() ) {
                break;
            }
        }

        $new = [
            'version'        => 1,
            'generated_at'   => $generated_at,
            'place_url'      => $place_url,
            'average_rating' => $average,
            'total_count'    => $total,
            'reviews'        => $clean,
        ];

        // Did anything visible change since last time? Only then is the page cache cleared.
        $changed = true;
        if ( is_array( $stored ) ) {
            $old = $stored;
            unset( $old['received_at'], $old['generated_at'] );
            $cmp = $new;
            unset( $cmp['generated_at'] );
            $changed = ( $old != $cmp ); // phpcs:ignore Universal.Operators.StrictComparisons -- loose on purpose (float/int).
        }

        // Saved in an option that is not loaded on ordinary page views.
        $new['received_at'] = time();
        update_option( 'webcare_reviews', $new, false );

        if ( $changed ) {
            try {
                do_action( 'webcare_reviews_updated', $new );
                // Breeze (standard on Cloudways) clears its page cache and Varnish on this action.
                do_action( 'breeze_clear_all_cache' );
            } catch ( \Throwable $e ) {
                // A cache problem must never stop the reviews being saved.
            }
        }

        $response = new WP_REST_Response( [ 'ok' => true, 'stored' => count( $clean ) ], 200 );
        webcare_feed_add_no_cache_headers( $response );
        return $response;
    } catch ( \Throwable $e ) {
        return webcare_reviews_bad_request();
    }
}

function webcare_register_reviews_route() {
    try {
        // Switched off: the address simply doesn't exist.
        if ( ! webcare_reviews_endpoint_enabled() ) {
            return;
        }
        register_rest_route(
            'webcare/v1',
            '/reviews',
            [
                'methods'             => 'POST',
                'callback'            => 'webcare_rest_reviews',
                // No declared arguments: WordPress would check them (and describe what is wrong)
                // BEFORE the signature check. Everything is checked after the signature instead.
                'permission_callback' => 'webcare_reviews_permission',
            ]
        );
    } catch ( \Throwable $e ) {
        return;
    }
}

/* ------------------------------------------------------------------
 * Showing reviews: [webcare_reviews count="6" show_summary="yes"]
 * ---------------------------------------------------------------- */

// Small, self-contained styling. Printed once per page, only when the shortcode is used.
function webcare_reviews_css() {
    return '<style id="webcare-reviews-css">'
        . '.webcare-reviews{margin:1em 0;font-family:inherit;color:inherit}'
        . '.webcare-reviews-summary{margin:0 0 1em;font-weight:600}'
        . '.webcare-reviews-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}'
        . '.webcare-reviews-card{border:1px solid rgba(0,0,0,.12);border-radius:6px;padding:16px;background:rgba(255,255,255,.6)}'
        . '.webcare-reviews-stars{font-size:1.2em;line-height:1;letter-spacing:2px;color:#e7a500}'
        . '.webcare-reviews-name{margin:.5em 0 0;font-weight:600}'
        . '.webcare-reviews-date{margin:0 0 .5em;font-size:.85em;opacity:.7}'
        . '.webcare-reviews-text{margin:0;white-space:pre-line}'
        . '.webcare-reviews-link{margin:1em 0 0}'
        . '</style>';
}

function webcare_reviews_stars_html( $rating ) {
    $rating = max( 1, min( 5, (int) $rating ) );
    /* translators: %d: star rating from 1 to 5 */
    $label = sprintf( __( '%d out of 5 stars', 'webcare' ), $rating );
    return '<span class="webcare-reviews-stars" role="img" aria-label="' . esc_attr( $label ) . '">'
        . '<span aria-hidden="true">' . str_repeat( "\xE2\x98\x85", $rating ) . str_repeat( "\xE2\x98\x86", 5 - $rating ) . '</span></span>';
}

function webcare_reviews_shortcode( $atts = [] ) {
    try {
        $atts = shortcode_atts( [ 'count' => 6, 'show_summary' => 'yes' ], is_array( $atts ) ? $atts : [], 'webcare_reviews' );

        $data = get_option( 'webcare_reviews', [] );
        if ( ! is_array( $data ) || empty( $data['reviews'] ) || ! is_array( $data['reviews'] ) ) {
            return '';
        }

        $count = absint( $atts['count'] );
        if ( $count < 1 ) {
            $count = 6;
        }
        $count        = min( $count, webcare_reviews_max_kept() );
        $show_summary = ! in_array( strtolower( trim( (string) $atts['show_summary'] ) ), [ 'no', 'false', '0', 'off' ], true );

        // Styles go out with every copy: some plugins run shortcodes once and throw the result away,
        // so "print once" could leave the visible copy unstyled. A repeated style block is harmless.
        $out = webcare_reviews_css();

        $out .= '<div class="webcare-reviews">';

        $total   = isset( $data['total_count'] ) ? (int) $data['total_count'] : 0;
        $average = isset( $data['average_rating'] ) && is_numeric( $data['average_rating'] ) ? (float) $data['average_rating'] : null;
        if ( $show_summary && null !== $average && $total > 0 ) {
            $summary = sprintf(
                /* translators: 1: average rating such as 4.8, 2: number of reviews */
                _n( 'Rated %1$s out of 5 from %2$s Google review', 'Rated %1$s out of 5 from %2$s Google reviews', $total, 'webcare' ),
                number_format_i18n( $average, 1 ),
                number_format_i18n( $total )
            );
            $out .= '<p class="webcare-reviews-summary">' . esc_html( $summary ) . '</p>';
        }

        $out  .= '<div class="webcare-reviews-grid">';
        $shown = 0;
        $format = (string) get_option( 'date_format', '' );
        if ( '' === $format ) {
            $format = 'j F Y';
        }
        foreach ( $data['reviews'] as $review ) {
            if ( $shown >= $count ) {
                break;
            }
            if ( ! is_array( $review ) || ! isset( $review['rating'], $review['name'], $review['date'] ) ) {
                continue;
            }
            $stamp = strtotime( (string) $review['date'] . ' 12:00:00 UTC' );
            $when  = false !== $stamp ? date_i18n( $format, $stamp, true ) : '';

            $out .= '<div class="webcare-reviews-card">'
                . webcare_reviews_stars_html( $review['rating'] )
                . '<p class="webcare-reviews-name">' . esc_html( (string) $review['name'] ) . '</p>'
                . ( '' !== $when ? '<p class="webcare-reviews-date">' . esc_html( $when ) . '</p>' : '' )
                . ( ! empty( $review['text'] ) ? '<p class="webcare-reviews-text">' . esc_html( (string) $review['text'] ) . '</p>' : '' )
                . '</div>';
            $shown++;
        }
        $out .= '</div>';

        if ( ! empty( $data['place_url'] ) && is_string( $data['place_url'] ) ) {
            $out .= '<p class="webcare-reviews-link"><a href="' . esc_url( $data['place_url'] ) . '" target="_blank" rel="noopener">'
                . esc_html__( 'See all our reviews on Google', 'webcare' ) . '</a></p>';
        }

        $out .= '</div>';

        return $out;
    } catch ( \Throwable $e ) {
        return '';
    }
}

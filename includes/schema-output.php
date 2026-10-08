<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * Business details -> "schema" markup for Google and AI assistants.
 *
 * Everything in here is defensive on purpose: this plugin runs on every client site, so
 * a problem here must never break a page. Output is wrapped in try/catch and, if anything
 * goes wrong, we simply publish nothing (or leave the SEO plugin's own markup untouched).
 */

/* ------------------------------------------------------------------
 * Fixed lists (business types and days of the week)
 * ---------------------------------------------------------------- */

// Plain-English label shown to the client => the schema.org type we publish.
// (Key = schema.org type, value = label. Only these are ever accepted.)
function webcare_business_types() {
    return [
        'MedicalClinic'           => __( 'Clinic (osteopathy, chiropractic, multi-therapy)', 'webcare' ),
        'Physiotherapy'           => __( 'Physiotherapy', 'webcare' ),
        'Physician'               => __( 'Doctor / GP / medical practice', 'webcare' ),
        'Dentist'                 => __( 'Dentist', 'webcare' ),
        'Optician'                => __( 'Optician', 'webcare' ),
        'HealthAndBeautyBusiness' => __( 'Health & beauty / wellbeing', 'webcare' ),
        'ProfessionalService'     => __( 'Professional service', 'webcare' ),
        'LocalBusiness'           => __( 'Other local business', 'webcare' ),
    ];
}

// Short key => [ English name used in the markup, label shown on screen ].
function webcare_business_days() {
    return [
        'mon' => [ 'schema' => 'Monday', 'label' => __( 'Monday', 'webcare' ) ],
        'tue' => [ 'schema' => 'Tuesday', 'label' => __( 'Tuesday', 'webcare' ) ],
        'wed' => [ 'schema' => 'Wednesday', 'label' => __( 'Wednesday', 'webcare' ) ],
        'thu' => [ 'schema' => 'Thursday', 'label' => __( 'Thursday', 'webcare' ) ],
        'fri' => [ 'schema' => 'Friday', 'label' => __( 'Friday', 'webcare' ) ],
        'sat' => [ 'schema' => 'Saturday', 'label' => __( 'Saturday', 'webcare' ) ],
        'sun' => [ 'schema' => 'Sunday', 'label' => __( 'Sunday', 'webcare' ) ],
    ];
}

/* ------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------- */

// Cut text to a number of characters (copes with accents when mbstring exists).
function webcare_substr( $text, $max ) {
    $text = (string) $text;
    return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) $max, 'UTF-8' ) : substr( $text, 0, (int) $max );
}

// A time must look exactly like 09:00 or 17:30 (24-hour clock).
function webcare_is_valid_time( $time ) {
    return is_string( $time ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
}

// A single stored text value, or '' if missing / not text.
function webcare_biz_text( $details, $key ) {
    if ( is_array( $details ) && isset( $details[ $key ] ) && is_string( $details[ $key ] ) ) {
        return trim( $details[ $key ] );
    }
    return '';
}

// A stored list (services / areas) as a clean array of non-empty strings.
// Accepts an array, or a string with one item per line.
function webcare_biz_list( $details, $key ) {
    $value = ( is_array( $details ) && isset( $details[ $key ] ) ) ? $details[ $key ] : [];
    if ( is_string( $value ) ) {
        $value = preg_split( '/\r\n|\r|\n/', $value );
        if ( ! is_array( $value ) ) {
            $value = [];
        }
    }
    if ( ! is_array( $value ) ) {
        return [];
    }
    $out = [];
    foreach ( $value as $item ) {
        if ( is_string( $item ) && '' !== trim( $item ) ) {
            $out[] = trim( $item );
        }
    }
    return $out;
}

// One day's saved opening hours, always returned in the same safe shape.
function webcare_biz_day_hours( $details, $day ) {
    $blank = [
        'closed' => false,
        'open1'  => '',
        'close1' => '',
        'open2'  => '',
        'close2' => '',
    ];
    if ( ! is_array( $details ) || empty( $details['hours'] ) || ! is_array( $details['hours'] )
        || empty( $details['hours'][ $day ] ) || ! is_array( $details['hours'][ $day ] ) ) {
        return $blank;
    }
    $saved = $details['hours'][ $day ];
    $blank['closed'] = ! empty( $saved['closed'] );
    foreach ( [ 'open1', 'close1', 'open2', 'close2' ] as $field ) {
        if ( isset( $saved[ $field ] ) && webcare_is_valid_time( $saved[ $field ] ) ) {
            $blank[ $field ] = $saved[ $field ];
        }
    }
    return $blank;
}

/* ------------------------------------------------------------------
 * Saved details
 * ---------------------------------------------------------------- */

function webcare_business_defaults() {
    return [
        'version'     => 1,
        'type'        => 'MedicalClinic',
        'name'        => '',
        'description' => '',
        'phone'       => '',
        'email'       => '',
        'street'      => '',
        'city'        => '',
        'county'      => '',
        'postcode'    => '',
        'country'     => 'GB',
        'hours'       => [],
        'services'    => [],
        'areas'       => [],
        'price'       => '',
    ];
}

// The one option where everything is kept. Always returns a full array, even if the
// stored value is missing or damaged.
function webcare_get_business_details() {
    $stored = get_option( 'webcare_business_details', [] );
    if ( ! is_array( $stored ) ) {
        $stored = [];
    }
    return array_merge( webcare_business_defaults(), $stored );
}

// Makes one value safe to publish in the markup: turns entities like &lt; back into plain
// characters, strips any HTML tags, and drops the value completely if a < or > is still left.
// (Dropping is fine for clinic content, and it guarantees no tag-like text reaches the markup.)
function webcare_biz_schema_string( $value ) {
    if ( ! is_string( $value ) ) {
        return '';
    }
    $value = wp_specialchars_decode( $value, ENT_QUOTES );
    // Any < or > at this point means tag-like text. Stripping it could silently cut a sentence
    // in half (e.g. "I <3 you" becomes "I"), so such values are left out entirely.
    if ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
        return '';
    }
    $value = trim( wp_strip_all_tags( $value ) );
    if ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) {
        return '';
    }
    return $value;
}

// Can the site owner switch this off for one site? Yes:
//   add_filter( 'webcare_publish_business_schema', '__return_false' );
function webcare_business_schema_enabled() {
    return (bool) apply_filters( 'webcare_publish_business_schema', true );
}

// We only publish when there is enough to be useful: a name AND a phone or an address.
function webcare_should_publish_business_schema( $details = null ) {
    if ( null === $details ) {
        $details = webcare_get_business_details();
    }
    if ( '' === webcare_biz_schema_string( webcare_biz_text( $details, 'name' ) ) ) {
        return false;
    }
    return '' !== webcare_biz_schema_string( webcare_biz_text( $details, 'phone' ) )
        || '' !== webcare_biz_schema_string( webcare_biz_text( $details, 'street' ) )
        || '' !== webcare_biz_schema_string( webcare_biz_text( $details, 'city' ) );
}

/* ------------------------------------------------------------------
 * Building the markup (pure: no database, no output)
 * ---------------------------------------------------------------- */

// Returns the business as a schema.org array. Empty fields are left out.
// Deliberately never includes ratings or reviews.
function webcare_build_business_schema( $details ) {
    if ( ! is_array( $details ) ) {
        return [];
    }

    $types = webcare_business_types();
    $type  = webcare_biz_text( $details, 'type' );
    if ( ! isset( $types[ $type ] ) ) {
        $type = 'LocalBusiness';
    }

    // Defence in depth: every piece of text is cleaned before it can be published.
    $clean = $details;
    foreach ( [ 'name', 'description', 'phone', 'email', 'street', 'city', 'county', 'postcode', 'country', 'price' ] as $text_key ) {
        $clean[ $text_key ] = webcare_biz_schema_string( webcare_biz_text( $details, $text_key ) );
    }
    foreach ( [ 'services', 'areas' ] as $list_key ) {
        $items = [];
        foreach ( webcare_biz_list( $details, $list_key ) as $item ) {
            $item = webcare_biz_schema_string( $item );
            if ( '' !== $item ) {
                $items[] = $item;
            }
        }
        $clean[ $list_key ] = $items;
    }
    $details = $clean;

    $schema = [ '@type' => $type ];

    $name =webcare_biz_text( $details, 'name' );
    if ( '' !== $name ) {
        $schema['name'] = $name;
    }

    $description = webcare_biz_text( $details, 'description' );
    if ( '' !== $description ) {
        $schema['description'] = $description;
    }

    $schema['url'] = home_url( '/' );

    $phone = webcare_biz_text( $details, 'phone' );
    if ( '' !== $phone ) {
        $schema['telephone'] = $phone;
    }

    $email = webcare_biz_text( $details, 'email' );
    if ( '' !== $email ) {
        $schema['email'] = $email;
    }

    // Address: only if there is something other than the country.
    $address = [];
    $map     = [
        'streetAddress'   => 'street',
        'addressLocality' => 'city',
        'addressRegion'   => 'county',
        'postalCode'      => 'postcode',
    ];
    foreach ( $map as $schema_key => $details_key ) {
        $value = webcare_biz_text( $details, $details_key );
        if ( '' !== $value ) {
            $address[ $schema_key ] = $value;
        }
    }
    if ( ! empty( $address ) ) {
        $country = webcare_biz_text( $details, 'country' );
        if ( '' !== $country ) {
            $address['addressCountry'] = $country;
        }
        $schema['address'] = array_merge( [ '@type' => 'PostalAddress' ], $address );
    }

    // Opening hours: one entry for each open session. Closed / unfilled days are left out.
    $hours = [];
    foreach ( webcare_business_days() as $day_key => $day ) {
        $day_hours = webcare_biz_day_hours( $details, $day_key );
        if ( $day_hours['closed'] ) {
            continue;
        }
        foreach ( [ [ 'open1', 'close1' ], [ 'open2', 'close2' ] ] as $pair ) {
            $opens  = $day_hours[ $pair[0] ];
            $closes = $day_hours[ $pair[1] ];
            if ( '' === $opens || '' === $closes || $closes <= $opens ) {
                continue;
            }
            $hours[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => 'https://schema.org/' . $day['schema'],
                'opens'     => $opens,
                'closes'    => $closes,
            ];
        }
    }
    if ( ! empty( $hours ) ) {
        $schema['openingHoursSpecification'] = $hours;
    }

    $areas = webcare_biz_list( $details, 'areas' );
    if ( ! empty( $areas ) ) {
        $served = [];
        foreach ( $areas as $area ) {
            $served[] = [
                '@type' => 'Place',
                'name'  => $area,
            ];
        }
        $schema['areaServed'] = $served;
    }

    $price = webcare_biz_text( $details, 'price' );
    if ( '' !== $price ) {
        $schema['priceRange'] = $price;
    }

    // Services are published as "knowsAbout" (a simple list of what the business does).
    $services = webcare_biz_list( $details, 'services' );
    if ( ! empty( $services ) ) {
        $schema['knowsAbout'] = $services;
    }

    return $schema;
}

/* ------------------------------------------------------------------
 * Which SEO plugin is in charge? (checked when needed, so plugin load order never matters)
 * ---------------------------------------------------------------- */

// Name of another SEO plugin that handles its own business markup, or '' if none.
function webcare_other_seo_plugin_name() {
    $plugins = [
        'RANK_MATH_VERSION'          => 'Rank Math',
        'AIOSEO_VERSION'             => 'All in One SEO',
        'SEOPRESS_VERSION'           => 'SEOPress',
        'THE_SEO_FRAMEWORK_VERSION'  => 'The SEO Framework',
    ];
    foreach ( $plugins as $constant => $label ) {
        if ( defined( $constant ) ) {
            return $label;
        }
    }
    return '';
}

// 'yoast_local' | 'yoast' | 'other_seo' | 'standalone'
function webcare_schema_route() {
    if ( defined( 'WPSEO_LOCAL_VERSION' ) ) {
        return 'yoast_local';
    }
    if ( defined( 'WPSEO_VERSION' ) ) {
        return 'yoast';
    }
    if ( '' !== webcare_other_seo_plugin_name() ) {
        return 'other_seo';
    }
    return 'standalone';
}

// Plain-English status for the Business details page: [ 'success' | 'warning', text ].
function webcare_business_status() {
    $route = webcare_schema_route();

    if ( ! webcare_business_schema_enabled() ) {
        return [ 'warning', __( 'Not published: switched off on this site.', 'webcare' ) ];
    }
    if ( 'yoast_local' === $route ) {
        return [ 'warning', __( 'Not published: Yoast Local SEO is active and handles this — ask APM.', 'webcare' ) ];
    }
    if ( 'other_seo' === $route ) {
        return [
            'warning',
            sprintf(
                /* translators: %s: name of an SEO plugin */
                __( 'Not published: %s is active and handles this — ask APM.', 'webcare' ),
                webcare_other_seo_plugin_name()
            ),
        ];
    }
    if ( ! webcare_should_publish_business_schema() ) {
        return [ 'warning', __( 'Not published yet: add at least a business name and phone or address.', 'webcare' ) ];
    }
    if ( 'yoast' === $route ) {
        return [ 'success', __( 'Published via Yoast SEO.', 'webcare' ) ];
    }
    return [ 'success', __( 'Published by Webcare.', 'webcare' ) ];
}

/* ------------------------------------------------------------------
 * Route 1: Yoast SEO (free) - add our details to Yoast's own "Organization" piece
 * ---------------------------------------------------------------- */

// Index of Yoast's organisation piece in the graph (the one whose @id ends in "#organization"),
// or null if there isn't one. We deliberately don't guess from the type.
function webcare_find_organization_key( $graph ) {
    foreach ( $graph as $key => $piece ) {
        if ( is_array( $piece ) && isset( $piece['@id'] ) && is_string( $piece['@id'] ) && '#organization' === substr( $piece['@id'], -13 ) ) {
            return $key;
        }
    }
    return null;
}

// Adds our fields to an existing Organization piece without touching Yoast's own
// @id, url, logo, image or sameAs.
function webcare_merge_business_into_organization( $org, $piece ) {
    // Business type: keep what's there (e.g. "Organization") and add ours.
    $types = [ 'Organization' ];
    if ( isset( $org['@type'] ) ) {
        $types = is_array( $org['@type'] ) ? array_values( $org['@type'] ) : [ $org['@type'] ];
    }
    if ( isset( $piece['@type'] ) && is_string( $piece['@type'] ) ) {
        $types[] = $piece['@type'];
    }
    // Only text types are kept (array_unique would misbehave on anything else).
    $text_types = [];
    foreach ( $types as $one_type ) {
        if ( is_string( $one_type ) && '' !== $one_type ) {
            $text_types[] = $one_type;
        }
    }
    $types = array_values( array_unique( $text_types ) );
    if ( ! empty( $types ) ) {
        $org['@type'] = ( 1 === count( $types ) ) ? $types[0] : $types;
    }

    // Name: the client's wording wins; Yoast's version is kept as an alternative name.
    if ( isset( $piece['name'] ) && '' !== $piece['name'] ) {
        if ( isset( $org['name'] ) && is_string( $org['name'] ) && '' !== $org['name']
            && $org['name'] !== $piece['name'] && empty( $org['alternateName'] ) ) {
            $org['alternateName'] = $org['name'];
        }
        $org['name'] = $piece['name'];
    }

    // Everything else from the client is added, except what Yoast owns.
    $leave_alone = [ '@context', '@id', '@type', 'name', 'alternateName', 'url', 'logo', 'image', 'sameAs' ];
    foreach ( $piece as $key => $value ) {
        if ( in_array( $key, $leave_alone, true ) ) {
            continue;
        }
        $org[ $key ] = $value;
    }
    return $org;
}

// Hooked to Yoast's 'wpseo_schema_graph'. Any problem = return Yoast's graph unchanged.
function webcare_filter_yoast_graph( $graph, $context = null ) {
    try {
        // Per-site off switch (see webcare_business_schema_enabled()).
        if ( ! webcare_business_schema_enabled() ) {
            return $graph;
        }
        if ( ! is_array( $graph ) || 'yoast' !== webcare_schema_route() ) {
            return $graph;
        }
        $details = webcare_get_business_details();
        if ( ! webcare_should_publish_business_schema( $details ) ) {
            return $graph;
        }
        $piece = webcare_build_business_schema( $details );
        if ( empty( $piece ) ) {
            return $graph;
        }

        $key = webcare_find_organization_key( $graph );
        if ( null === $key ) {
            // Yoast has no organisation piece (e.g. site set to a person): add our own.
            $piece['@id'] = home_url( '/#organization' );
            $graph[]      = $piece;
            return $graph;
        }

        $graph[ $key ] = webcare_merge_business_into_organization( $graph[ $key ], $piece );
        return $graph;
    } catch ( \Throwable $e ) {
        error_log( '[Webcare] Schema error (Yoast route): ' . $e->getMessage() );
        return $graph;
    }
}

/* ------------------------------------------------------------------
 * Route 2: no SEO plugin - Webcare prints its own block in the page <head>
 * ---------------------------------------------------------------- */

function webcare_output_business_schema() {
    try {
        // Per-site off switch (see webcare_business_schema_enabled()).
        if ( ! webcare_business_schema_enabled() ) {
            return;
        }
        if ( 'standalone' !== webcare_schema_route() ) {
            return;
        }
        if ( is_admin() || is_feed() || is_customize_preview() ) {
            return;
        }
        // Don't add it inside the Divi visual builder.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check.
        if ( isset( $_GET['et_fb'] ) ) {
            return;
        }
        $details = webcare_get_business_details();
        if ( ! webcare_should_publish_business_schema( $details ) ) {
            return;
        }
        $schema = webcare_build_business_schema( $details );
        if ( empty( $schema ) ) {
            return;
        }
        $schema = array_merge(
            [
                '@context' => 'https://schema.org',
                '@id'      => home_url( '/#organization' ),
            ],
            $schema
        );
        $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
        if ( ! is_string( $json ) || '' === $json ) {
            return;
        }
        // The < > & characters are already escaped by the JSON flags above, so this is safe to print as-is.
        echo "\n" . '<script type="application/ld+json" id="webcare-schema">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } catch ( \Throwable $e ) {
        error_log( '[Webcare] Schema error (standalone route): ' . $e->getMessage() );
    }
}

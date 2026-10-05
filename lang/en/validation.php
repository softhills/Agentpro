<?php

/**
 * What the validator calls things, and the two messages it cannot say well on
 * its own.
 *
 * Laravel builds an attribute name out of the field name, which is the database
 * column, which is written for the schema and not for the person filling in the
 * form. That produced "The lng field must be between -180 and 180." on a
 * listing form — reported by a lister who could not tell which box it meant, and
 * could not save until they guessed. "lng" is not a word, and a form that
 * refuses to save while naming a field nobody can see is a form that cannot be
 * finished.
 *
 * Only the keys below are overridden; everything else still comes from the
 * framework's own file, which the loader merges underneath this one.
 */
return [

    /*
     * Keyed by the form field. Nested and wildcard paths are matched the way
     * the rules spell them, so "fees.*.amount" covers every row of the move-in
     * cost table rather than appearing as "fees.0.amount".
     */
    'attributes' => [
        // The listing form
        'listing_type'         => 'type',
        'intent'               => 'rent or sale',
        'build_status'         => 'build status',
        'area_id'              => 'area',
        'address_line'         => 'street address',
        'lat'                  => 'latitude',
        'lng'                  => 'longitude',
        'what3words'           => 'three-word address',
        'website_url'          => 'website',
        'unit.price'           => 'price',
        'unit.price_period'    => 'price period',
        'unit.bedrooms'        => 'bedrooms',
        'unit.bathrooms'       => 'bathrooms',
        'unit.toilets'         => 'toilets',
        'unit.floor_area_sqm'  => 'floor area',
        'unit.available_from'  => 'available from',
        'fees.*.label'         => 'fee name',
        'fees.*.amount'        => 'fee amount',
        'fees.*.payee'         => 'who the fee is paid to',
        'fees.*.is_refundable' => 'refundable',
        'titles.*'             => 'title document',
        'amenities.*'          => 'amenity',

        // Payouts and money
        'bank_code'            => 'bank',
        'account_number'       => 'account number',

        // The admin console
        'centroid_lat'         => 'centre latitude',
        'centroid_lng'         => 'centre longitude',
        'default_zoom'         => 'default zoom',
        'is_scan_coverage'     => '3D capture',
        'technician_id'        => 'technician',
        'user_id'              => 'lister',
        'into'                 => 'what to merge it into',
    ],

    /*
     * A coordinate is the one thing on the listing form a lister is asked for
     * that they cannot simply read off the property, so the refusals say where
     * the numbers come from and roughly how big they should be. Both failures
     * below are things listers actually do: pasting the pair into one box, and
     * copying eastings and northings off a survey plan — which are metres, run
     * to six figures, and are what a land listing has to hand.
     */
    'custom' => [
        'lat' => [
            'numeric' => 'Latitude is a single number, like 6.4584. If you have both numbers together, the first one goes here and the second in longitude.',
            'between' => 'Latitude must be between :min and :max, and anywhere in Nigeria it is between 4 and 14. A longer number is usually a northing from a survey plan, which is measured in metres rather than degrees.',
        ],
        'lng' => [
            'numeric' => 'Longitude is a single number, like 3.4795. If you have both numbers together, the second one goes here and the first in latitude.',
            'between' => 'Longitude must be between :min and :max, and anywhere in Nigeria it is between 2.7 and 14.7. A longer number is usually an easting from a survey plan, which is measured in metres rather than degrees.',
        ],
    ],

];

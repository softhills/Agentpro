<?php

namespace App\Support;

/**
 * Controlled vocabularies from the PRD.
 *
 * These are closed lists, not free text (FR-M6-04). They live in code rather
 * than the database because changing one is a product decision with legal
 * consequence, and should arrive through review and a deployment — unlike
 * coverage areas and prices, which Operations edits at runtime.
 */
final class Vocab
{
    /**
     * The nineteen title types, grouped as they are presented to the lister.
     * Q6 is still open on ENGIS / AEGIS — both are carried verbatim from the
     * source notes and must be confirmed before this list is treated as frozen.
     */
    public const TITLE_TYPES = [
        'Statutory' => [
            'certificate_of_occupancy' => 'Certificate of Occupancy',
            'right_of_occupancy'       => 'Right of Occupancy',
            'governors_consent'        => "Governor's Consent",
            'ministers_consent'        => "Minister's Consent",
            'excision_gazette'         => 'Excision / Gazette',
        ],
        'Deed & instrument' => [
            'deed_of_assignment'       => 'Deed of Assignment',
            'deed_of_lease'            => 'Deed of Lease',
            'power_of_attorney'        => 'Power of Attorney',
            'registered_conveyance'    => 'Registered Conveyance',
            'contract_of_sale'         => 'Contract of Sale',
            'letter_of_administration' => 'Letter of Administration / Probate',
        ],
        'Customary & administrative' => [
            'customary_omo_onile'      => 'Customary / Omo Onile Land',
            'family_communal'          => 'Family / Communal Land',
            'survey_plan'              => 'Survey Plan',
            'registered_survey_plan'   => 'Registered Survey Plan',
        ],
        'Planning & agency' => [
            'building_plan_approval'   => 'Building Plan Approval',
            'fcda'                     => 'FCDA',
            'aegis'                    => 'AEGIS',
            'engis'                    => 'ENGIS',
        ],
    ];

    /** RealSure components, in the order they appear on the listing (FR-M6-02). */
    public const REALSURE_COMPONENTS = [
        'title_verification'     => 'Title verification',
        'search_report'          => 'Search report',
        'regulatory_compliance'  => 'Regulatory compliance & approvals',
        'community_investigation'=> 'Community investigation',
        'valuation'              => 'Valuation',
        'legal_documentation'    => 'Legal documentation & conveyancing',
        'floor_plans'            => 'Floor plans',
        'immersive_capture'      => '360 & 3D/Virtual tour',
        'drone_photography'      => 'Drone photography',
        'hd_photography'         => 'High-definition photography',
    ];

    /** FR-M2-04. 'price_drop' is derived from price history, never chosen. */
    public const TAGS = [
        'special_offer' => 'Special offer',
        'price_drop'    => 'Price drop',
        'payment_plan'  => 'Payment plan',
        'financing'     => 'Financing',
    ];

    /** FR-M6-07 */
    public const REPORT_REASONS = [
        'fraudulent'     => 'Listing appears fraudulent',
        'duplicate'      => 'Duplicate of another listing',
        'already_taken'  => 'Already sold or rented',
        'wrong_price'    => 'Price is wrong or misleading',
        'wrong_location' => 'Location is wrong',
        'offensive'      => 'Offensive or inappropriate content',
    ];

    public static function titleTypeLabel(string $key): string
    {
        foreach (self::TITLE_TYPES as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }

        return str_replace('_', ' ', ucfirst($key));
    }

    /** @return array<string,string> flat key => label */
    public static function flatTitleTypes(): array
    {
        return array_merge(...array_values(self::TITLE_TYPES));
    }
}

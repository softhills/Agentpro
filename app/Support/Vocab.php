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

    /**
     * Which of those components are verification, and which are production.
     *
     * FR-M6-02 lists all ten together and the listing page shows them together,
     * because a buyer wants to see everything that was done. The distinction
     * exists for one specific decision: what is enough to earn the badge.
     *
     * "A REALSURE listing means that Agentpro has conducted extra verification
     * on the property." Sending a photographer is a service the lister bought;
     * it establishes nothing about the property that was not already visible.
     * A badge granted on the strength of drone footage alone would say
     * "verified" about a listing nobody checked, which is the one failure that
     * would make the mark worthless — and the mark is the product.
     *
     * So the badge is gated on the first group. The second still appears on the
     * listing, still carries a date and an officer, and is still worth showing.
     *
     * @var list<string>
     */
    public const REALSURE_VERIFICATION = [
        'title_verification',
        'search_report',
        'regulatory_compliance',
        'community_investigation',
        'valuation',
        'legal_documentation',
    ];

    /** @var list<string> */
    public const REALSURE_PRODUCTION = [
        'floor_plans',
        'immersive_capture',
        'drone_photography',
        'hd_photography',
    ];

    public static function isVerificationComponent(string $component): bool
    {
        return in_array($component, self::REALSURE_VERIFICATION, true);
    }

    /** FR-M2-04. 'price_drop' is derived from price history, never chosen. */
    public const TAGS = [
        'special_offer' => 'Special offer',
        'price_drop'    => 'Price drop',
        'payment_plan'  => 'Payment plan',
        'financing'     => 'Financing',
    ];

    /**
     * The tags a lister may actually set.
     *
     * price_drop is absent, and this list exists so that fact is enforced in
     * one place rather than asserted in several. It gates the listing form's
     * validation and Property::allTags() alike — the second one matters most,
     * because a value written straight into the column by a seeder, a console
     * command or a future import would otherwise be believed.
     *
     * @var list<string>
     */
    public const LISTER_TAGS = ['special_offer', 'payment_plan', 'financing'];

    /**
     * FR-M2-06 / FR-M12-01: the reason-code catalogue.
     *
     * A closed list rather than free text, because reject reasons are the raw
     * material for two things beyond the individual decision: telling a lister
     * what to fix, and telling the business which part of the submission flow is
     * failing listers at scale. Free text answers neither.
     */
    public const REJECT_REASONS = [
        'incomplete_fees'      => 'Cost breakdown incomplete or implausible',
        'poor_media'           => 'Photographs missing, unusable or not of this property',
        'title_mismatch'       => 'Declared title does not match the documents',
        'wrong_location'       => 'Location or address is wrong',
        'duplicate'            => 'Duplicate of an existing listing',
        'suspected_fraud'      => 'Suspected fraudulent listing',
        'price_implausible'    => 'Price is implausible for the area',
        'description_quality'  => 'Description is inadequate or misleading',
        'prohibited_content'   => 'Contains prohibited or offensive content',
    ];

    /** FR-M2-07: taking a live listing down. */
    /**
     * Why a listing is coming off the market (FR-M2-07, FR-M2-09).
     *
     * Asked as an outcome rather than as a reason code, because the answer
     * decides where the listing goes rather than merely annotating it: sold and
     * rented are public states that carry the listing into the archive, and
     * everything else is an unpublish that ends its public life quietly.
     *
     * "Other" is last and unglamorous on purpose. If picking it were as easy as
     * the first two, it would absorb the closings that are actually sales, and
     * the archive would undercount the only thing it exists to show.
     *
     * @var array<string,string>
     */
    public const CLOSE_OUTCOMES = [
        'sold'   => 'Sold',
        'rented' => 'Rented',
        'other'  => 'Other reason',
    ];

    /** The states each outcome lands the listing in. */
    public const CLOSE_OUTCOME_STATES = [
        'sold'   => 'sold',
        'rented' => 'rented',
        'other'  => 'unpublished',
    ];

    public const UNPUBLISH_REASONS = [
        'lister_request'   => 'Requested by the lister',
        'no_longer_available' => 'Property is no longer available',
        'listing_error'    => 'Listed in error',
        'upheld_report'    => 'Report from a seeker upheld',
        'suspected_fraud'  => 'Suspected fraudulent listing',
        'title_dispute'    => 'Title dispute raised',
        'policy_breach'    => 'Breach of listing policy',
    ];

    /**
     * The subset a lister may choose when unlisting their own property.
     *
     * The rest of UNPUBLISH_REASONS are findings — a report upheld, suspected
     * fraud, a title dispute — and a finding is something the platform records
     * about a listing, not something its owner can stamp on it. Letting a
     * lister write "suspected fraud" into their own audit trail would corrupt
     * the only record that says who concluded what.
     *
     * @var list<string>
     */
    public const LISTER_UNPUBLISH_REASONS = [
        'no_longer_available',
        'lister_request',
        'listing_error',
    ];

    /** @return array<string,string> */
    public static function listerUnpublishReasons(): array
    {
        return array_intersect_key(
            self::UNPUBLISH_REASONS,
            array_flip(self::LISTER_UNPUBLISH_REASONS)
        );
    }

    /** FR-M6-07 */
    public const REPORT_REASONS = [
        'fraudulent'     => 'Listing appears fraudulent',
        'duplicate'      => 'Duplicate of another listing',
        'already_taken'  => 'Already sold or rented',
        'wrong_price'    => 'Price is wrong or misleading',
        'wrong_location' => 'Location is wrong',
        'offensive'      => 'Offensive or inappropriate content',
    ];

    /**
     * Every title key, flattened out of the presentation groups.
     *
     * @return list<string>
     */
    public static function titleTypeKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::TITLE_TYPES)));
    }

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

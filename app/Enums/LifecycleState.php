<?php

namespace App\Enums;

/**
 * Listing lifecycle (FR-M2-05).
 *
 * The two questions this enum exists to answer consistently are "is this
 * publicly visible?" and "is this still on the market?" — asked in the search
 * query, the policy layer and the sitemap, which must never disagree.
 */
enum LifecycleState: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Published   = 'published';
    case Unpublished = 'unpublished';
    case Expired     = 'expired';
    case Sold        = 'sold';
    case Rented      = 'rented';
    case Rejected    = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Draft',
            self::Submitted   => 'Submitted',
            self::UnderReview => 'Under review',
            self::Published   => 'Published',
            self::Unpublished => 'Unpublished',
            self::Expired     => 'Expired',
            self::Sold        => 'Sold',
            self::Rented      => 'Rented',
            self::Rejected    => 'Rejected',
        };
    }

    /** States a guest may load a listing page in. */
    public static function publiclyVisible(): array
    {
        return [self::Published->value, self::Sold->value, self::Rented->value];
    }

    /** FR-M2-09: excluded from default results, findable behind the filter. */
    public static function closed(): array
    {
        return [self::Sold->value, self::Rented->value];
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Sold, self::Rented], true);
    }
}

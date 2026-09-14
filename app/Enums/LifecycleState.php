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

    /**
     * States a listing can be taken off the market from.
     *
     * Expired is included deliberately. A listing whose display period ran out
     * is still a listing whose property was let last month, and the lister
     * should be able to say so — otherwise the only listings that ever reach
     * the archive are the ones someone remembered to close in time, which
     * makes the archive a measure of admin diligence rather than of activity.
     */
    public function canBeUnlisted(): bool
    {
        return in_array($this, [self::Published, self::Expired], true);
    }

    /**
     * Whether a lister may put this back on the market without re-review.
     *
     * Only from a closing they declared themselves. An unpublish is a decision
     * somebody else made about the listing — usually a report, a title dispute
     * or a policy breach — and undoing it with a button would make the
     * moderation power meaningless. That path back is `submit`, through the
     * queue, which is where it belongs.
     */
    public function canBeRelisted(): bool
    {
        return $this->isClosed();
    }
}
